<?php

declare(strict_types=1);

namespace justinholtweb\telescope\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\models\Site;
use justinholtweb\telescope\auth\RefreshTokenProvider;
use justinholtweb\telescope\auth\ServiceAccountCredentials;
use justinholtweb\telescope\auth\ServiceAccountTokenProvider;
use justinholtweb\telescope\auth\TokenProviderInterface;
use justinholtweb\telescope\errors\AuthException;
use justinholtweb\telescope\errors\TelescopeException;
use justinholtweb\telescope\ga4\Client;
use justinholtweb\telescope\ga4\Period;
use justinholtweb\telescope\ga4\ReportRequest;
use justinholtweb\telescope\helpers\PathHelper;
use justinholtweb\telescope\http\GuzzleHttpClient;
use justinholtweb\telescope\http\HttpClientInterface;
use justinholtweb\telescope\models\ConnectionStatus;
use justinholtweb\telescope\models\Settings;
use justinholtweb\telescope\Plugin;
use justinholtweb\telescope\reports\PageReport;
use justinholtweb\telescope\reports\ReportBuilder;
use justinholtweb\telescope\reports\ReportOptions;
use justinholtweb\telescope\reports\ReportSection;
use yii\caching\CacheInterface;
use yii\caching\TagDependency;

/**
 * The plugin's front door: hands back cached {@see PageReport}s for elements,
 * paths and the property as a whole.
 *
 * All of the Craft-specific work — resolving which GA4 property a site maps to,
 * turning an element into a page path, caching — happens here so the classes
 * underneath stay plain PHP.
 */
class Analytics extends Component
{
    public const CACHE_TAG = 'telescope';
    public const CACHE_KEY_PREFIX = 'telescope:';

    /**
     * Overridable so tests can supply an array cache and a fake transport.
     */
    public ?CacheInterface $cache = null;

    public ?HttpClientInterface $httpClient = null;

    /** @var array<string, TokenProviderInterface> */
    private array $tokenProviders = [];

    /**
     * The report for an element with a public URL.
     *
     * Anything without a URL (a draft, a structure-only entry, a category with
     * no template) has no page in GA4 and gets an empty report.
     */
    public function getReportForElement(
        ElementInterface $element,
        ?Period $period = null,
        bool $useCache = true,
        ?array $sections = null,
    ): PageReport {
        $url = $element->getUrl();

        if ($url === null) {
            return PageReport::empty();
        }

        return $this->getReport($url, $element->siteId, $period, $useCache, $sections);
    }

    /**
     * The report for a URL or path.
     *
     * @param list<string>|null $sections limit the report to these sections —
     *                                    each one saved is one API call not made.
     *                                    Null means whatever the settings enable.
     */
    public function getReport(
        string $url,
        ?int $siteId = null,
        ?Period $period = null,
        bool $useCache = true,
        ?array $sections = null,
    ): PageReport {
        $settings = $this->getSettings();
        $period ??= $settings->getDefaultPeriod();
        $site = $this->resolveSite($siteId);

        $path = PathHelper::pathFromUrl($url, $settings->includeQueryString);

        if ($path === null) {
            return PageReport::empty('', $period->label);
        }

        $options = $this->createOptions($settings, $site, $sections);
        $cacheKey = $this->reportCacheKey($path, $site, $period, $options);
        $cache = $this->getCache();

        if ($useCache && $settings->getCacheDuration() > 0) {
            $cached = $cache->get($cacheKey);

            if (is_array($cached)) {
                return PageReport::fromArray($cached);
            }
        }

        $builder = $this->createBuilder($site, $options);

        if ($builder === null) {
            return PageReport::empty($path, $period->label)
                ->withErrors(['Telescope is not configured yet — add your Google credentials and GA4 property ID in the plugin settings.']);
        }

        try {
            $report = $builder->build($path, $period);
        } catch (TelescopeException $e) {
            Craft::error("Telescope report failed for {$path}: {$e->getMessage()}", __METHOD__);

            return PageReport::empty($path, $period->label)->withErrors([$e->getMessage()]);
        }

        // Only cache clean reports: caching a partial failure would keep the
        // error on screen for the whole cache window after it was fixed.
        if ($settings->getCacheDuration() > 0 && !$report->hasErrors()) {
            $cache->set(
                $cacheKey,
                $report->toArray(),
                $settings->getCacheDuration(),
                new TagDependency(['tags' => [self::CACHE_TAG]]),
            );
        }

        return $report;
    }

    /**
     * The property's most-viewed pages.
     *
     * @return list<array{path: string, title: string, views: int, users: int}>
     */
    public function getTopPages(
        ?int $siteId = null,
        ?Period $period = null,
        ?int $limit = null,
        bool $useCache = true,
    ): array {
        $settings = $this->getSettings();
        $period ??= $settings->getDefaultPeriod();
        $limit ??= $settings->getWidgetLimit();
        $site = $this->resolveSite($siteId);

        $cacheKey = self::CACHE_KEY_PREFIX . 'top:' . ($site?->id ?? 0) . ':' . $period->cacheKey() . ":{$limit}";
        $cache = $this->getCache();

        if ($useCache && $settings->getCacheDuration() > 0) {
            $cached = $cache->get($cacheKey);

            if (is_array($cached)) {
                return $cached;
            }
        }

        $builder = $this->createBuilder($site);

        if ($builder === null) {
            return [];
        }

        try {
            $pages = $builder->topPages($period, $limit);
        } catch (TelescopeException $e) {
            Craft::error("Telescope top-pages report failed: {$e->getMessage()}", __METHOD__);

            return [];
        }

        if ($settings->getCacheDuration() > 0) {
            $cache->set(
                $cacheKey,
                $pages,
                $settings->getCacheDuration(),
                new TagDependency(['tags' => [self::CACHE_TAG]]),
            );
        }

        return $pages;
    }

    /**
     * Drop every cached report.
     */
    public function clearCache(): void
    {
        TagDependency::invalidate($this->getCache(), self::CACHE_TAG);
        $this->tokenProviders = [];
    }

    /**
     * Verify credentials and property access with one real API call.
     */
    public function testConnection(?int $siteId = null): ConnectionStatus
    {
        $settings = $this->getSettings();
        $site = $this->resolveSite($siteId);
        $propertyId = $settings->getPropertyIdForSite($site->handle);
        $checks = [];

        if ($propertyId === '') {
            return ConnectionStatus::failure('No GA4 property ID is configured.');
        }

        $checks[] = "Property ID: {$propertyId}";

        try {
            $provider = $this->createTokenProvider($settings);
            $token = $provider->getToken();
            $checks[] = 'Access token obtained (' . $token->secondsRemaining(time()) . 's remaining)';
        } catch (AuthException $e) {
            return ConnectionStatus::failure($e->getMessage(), $checks, $propertyId);
        }

        $client = new Client($propertyId, $provider, $this->getHttpClient());

        try {
            $response = $client->runReport(
                ReportRequest::for(Period::preset('last7days'))->metrics('screenPageViews'),
            );
        } catch (TelescopeException $e) {
            return ConnectionStatus::failure($e->getMessage(), $checks, $propertyId);
        }

        $views = $response->isEmpty() ? 0 : $response->metricInt(0, 'screenPageViews');
        $checks[] = "Data API responded ({$views} page views in the last 7 days)";

        return ConnectionStatus::success('Connected to Google Analytics.', $checks, $propertyId);
    }

    /**
     * Build a report builder for a site, or null when the plugin is not
     * configured for it.
     */
    public function createBuilder(?Site $site = null, ?ReportOptions $options = null): ?ReportBuilder
    {
        $settings = $this->getSettings();
        $propertyId = $settings->getPropertyIdForSite($site?->handle);

        if ($propertyId === '' || !$settings->isConfigured($site?->handle)) {
            return null;
        }

        try {
            $provider = $this->createTokenProvider($settings);
        } catch (AuthException $e) {
            Craft::error("Telescope could not build credentials: {$e->getMessage()}", __METHOD__);

            return null;
        }

        $client = new Client($propertyId, $provider, $this->getHttpClient());

        return new ReportBuilder($client, $options ?? $this->createOptions($settings, $site));
    }

    /**
     * @param list<string>|null $sections narrow the enabled sections further —
     *                                    a caller that only needs the headline
     *                                    numbers should not pay for six calls.
     *                                    Never widens what the settings allow.
     */
    public function createOptions(
        ?Settings $settings = null,
        ?Site $site = null,
        ?array $sections = null,
    ): ReportOptions {
        $settings ??= $this->getSettings();
        $enabled = $settings->getSections();

        if ($sections !== null) {
            $enabled = ReportSection::normalize(array_intersect($enabled, $sections));
        }

        return new ReportOptions(
            matchType: $settings->pathMatchType ?: ReportRequest::MATCH_EXACT,
            hostname: $settings->filterByHostname ? $this->hostnameForSite($site) : null,
            rowLimit: $settings->getRowLimit(),
            knownHosts: $this->knownHosts(),
            sections: $enabled,
        );
    }

    /**
     * @throws AuthException
     */
    public function createTokenProvider(?Settings $settings = null): TokenProviderInterface
    {
        $settings ??= $this->getSettings();
        $signature = $settings->authMode . '|' . sha1($settings->getCredentials() . $settings->getRefreshToken());

        if (isset($this->tokenProviders[$signature])) {
            return $this->tokenProviders[$signature];
        }

        $provider = $settings->authMode === Settings::AUTH_OAUTH
            ? new RefreshTokenProvider(
                $settings->getClientId(),
                $settings->getClientSecret(),
                $settings->getRefreshToken(),
                $this->getHttpClient(),
            )
            : new ServiceAccountTokenProvider(
                ServiceAccountCredentials::resolve($settings->getCredentials()),
                $this->getHttpClient(),
            );

        return $this->tokenProviders[$signature] = $provider;
    }

    /**
     * Every hostname this installation serves, used to tell internal referrers
     * from external ones.
     *
     * @return list<string>
     */
    public function knownHosts(): array
    {
        $hosts = [];

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $host = PathHelper::hostFromUrl($site->getBaseUrl() ?? null);

            if ($host !== null && !in_array($host, $hosts, true)) {
                $hosts[] = $host;
            }
        }

        return $hosts;
    }

    public function hostnameForSite(?Site $site): ?string
    {
        $site ??= Craft::$app->getSites()->getCurrentSite();

        return PathHelper::hostFromUrl($site->getBaseUrl() ?? null);
    }

    public function getSettings(): Settings
    {
        /** @var Settings $settings */
        $settings = Plugin::getInstance()->getSettings();

        return $settings;
    }

    /**
     * The site a report belongs to. An unknown ID falls back to the current
     * site rather than failing — a stale widget setting should not take the
     * dashboard down.
     */
    private function resolveSite(?int $siteId): Site
    {
        $sites = Craft::$app->getSites();

        if ($siteId === null) {
            return $sites->getCurrentSite();
        }

        return $sites->getSiteById($siteId) ?? $sites->getCurrentSite();
    }

    private function reportCacheKey(string $path, ?Site $site, Period $period, ReportOptions $options): string
    {
        return self::CACHE_KEY_PREFIX . 'report:' . implode(':', [
            $site?->id ?? 0,
            $period->cacheKey(),
            $options->fingerprint(),
            sha1($path),
        ]);
    }

    private function getCache(): CacheInterface
    {
        return $this->cache ??= Craft::$app->getCache();
    }

    private function getHttpClient(): HttpClientInterface
    {
        return $this->httpClient ??= new GuzzleHttpClient();
    }
}
