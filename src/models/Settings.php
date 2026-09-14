<?php

declare(strict_types=1);

namespace justinholtweb\telescope\models;

use craft\base\Model;
use craft\helpers\App;
use justinholtweb\telescope\auth\ServiceAccountCredentials;
use justinholtweb\telescope\errors\AuthException;
use justinholtweb\telescope\ga4\Client;
use justinholtweb\telescope\ga4\Period;
use justinholtweb\telescope\ga4\ReportRequest;
use justinholtweb\telescope\reports\DashboardSection;
use justinholtweb\telescope\reports\ReportSection;

/**
 * Plugin settings.
 *
 * Every credential-bearing setting is read through {@see App::parseEnv()} so it
 * can be stored as `$GOOGLE_ANALYTICS_...` in `.env` and kept out of project
 * config — which is where secrets belong when project config is committed.
 */
class Settings extends Model
{
    public const AUTH_SERVICE_ACCOUNT = 'serviceAccount';
    public const AUTH_OAUTH = 'oauth';

    /**
     * How the plugin authenticates with Google.
     */
    public string $authMode = self::AUTH_SERVICE_ACCOUNT;

    /**
     * Service account JSON — either the key itself, a path to the key file, or
     * an environment variable holding one of those.
     */
    public string $credentials = '';

    public string $clientId = '';

    public string $clientSecret = '';

    public string $refreshToken = '';

    /**
     * The default GA4 property, e.g. `properties/123456789` or `123456789`.
     */
    public string $propertyId = '';

    /**
     * Per-site property overrides, keyed by site handle.
     *
     * @var array<string, string>
     */
    public array $sitePropertyIds = [];

    /**
     * Per-site service account overrides, keyed by site handle.
     *
     * A multi-site install whose sites report into different GA4 accounts —
     * separate clients, separate Google Cloud projects — needs a credential
     * per site, not just a property ID per site.
     *
     * @var array<string, string>
     */
    public array $siteCredentials = [];

    /**
     * Per-site OAuth refresh token overrides, keyed by site handle.
     *
     * The OAuth *client* stays global: one application, one consent screen.
     * What differs per site is which Google account authorised it.
     *
     * @var array<string, string>
     */
    public array $siteRefreshTokens = [];

    /**
     * Add a `hostName` filter derived from each site's base URL.
     *
     * Required when several Craft sites report into one GA4 property, since
     * `/about` in each of them is a different page with the same path.
     */
    public bool $filterByHostname = false;

    /**
     * How long a report is cached, in seconds.
     */
    public int $cacheDuration = 600;

    /**
     * The period selected when a report is first opened.
     */
    public string $defaultPeriod = 'last28days';

    /**
     * How `pagePath` is matched against an entry's URL.
     */
    public string $pathMatchType = ReportRequest::MATCH_EXACT;

    /**
     * Keep query strings on the path when matching.
     */
    public bool $includeQueryString = false;

    /**
     * Rows shown in each breakdown table.
     */
    public int $rowLimit = 10;

    /**
     * Which report sections to fetch.
     *
     * @var list<string>
     */
    public array $sections = ReportSection::ALL;

    /**
     * Which panels the site-wide dashboard fetches.
     *
     * @var list<string>
     */
    public array $dashboardSections = DashboardSection::ALL;

    /**
     * Show the analytics panel in the entry sidebar automatically, without
     * needing the Telescope field added to a field layout.
     */
    public bool $autoAttachToEntries = false;

    /**
     * Sections whose entries get the automatic panel — empty means all of them.
     *
     * @var list<string>
     */
    public array $autoAttachSections = [];

    /**
     * Rows in the "Top pages" dashboard widget.
     */
    public int $widgetLimit = 10;

    /**
     * @return array<string, string>
     */
    public function attributeLabels(): array
    {
        return [
            'authMode' => 'Authentication method',
            'credentials' => 'Service account credentials',
            'clientId' => 'OAuth client ID',
            'clientSecret' => 'OAuth client secret',
            'refreshToken' => 'OAuth refresh token',
            'propertyId' => 'GA4 property ID',
            'sitePropertyIds' => 'Per-site property IDs',
            'siteCredentials' => 'Per-site service accounts',
            'siteRefreshTokens' => 'Per-site refresh tokens',
            'filterByHostname' => 'Filter by hostname',
            'cacheDuration' => 'Cache duration',
            'defaultPeriod' => 'Default period',
            'pathMatchType' => 'Path match type',
            'includeQueryString' => 'Include query strings',
            'rowLimit' => 'Table rows',
            'sections' => 'Report sections',
            'dashboardSections' => 'Dashboard panels',
            'autoAttachToEntries' => 'Show on all entries',
            'autoAttachSections' => 'Limited to sections',
            'widgetLimit' => 'Widget rows',
        ];
    }

    /**
     * @return array<int, mixed>
     */
    public function rules(): array
    {
        return [
            [['authMode'], 'in', 'range' => [self::AUTH_SERVICE_ACCOUNT, self::AUTH_OAUTH]],
            [['pathMatchType'], 'in', 'range' => [
                ReportRequest::MATCH_EXACT,
                ReportRequest::MATCH_BEGINS_WITH,
                ReportRequest::MATCH_CONTAINS,
            ]],
            [['cacheDuration'], 'integer', 'min' => 0, 'max' => 604800],
            [['rowLimit'], 'integer', 'min' => 1, 'max' => 100],
            [['widgetLimit'], 'integer', 'min' => 1, 'max' => 50],
            [['defaultPeriod'], 'validatePeriod'],
            [['propertyId'], 'validatePropertyId'],
            // Credentials are checked for correctness but never *required*:
            // a fresh install has to be able to save its other settings before
            // anyone has been to Google Cloud to make a key.
            [['credentials'], 'validateCredentials'],
            [['siteCredentials'], 'validateSiteCredentials'],
            [['credentials', 'clientId', 'clientSecret', 'refreshToken', 'propertyId'], 'string'],
            [['filterByHostname', 'includeQueryString', 'autoAttachToEntries'], 'boolean'],
            [['sitePropertyIds', 'siteCredentials', 'siteRefreshTokens', 'sections', 'autoAttachSections', 'dashboardSections'], 'safe'],
        ];
    }

    public function validatePeriod(string $attribute): void
    {
        $value = (string)$this->$attribute;

        if (isset(Period::presetOptions()[$value]) || Period::isValidDate($value)) {
            return;
        }

        $this->addError($attribute, 'Choose a preset period or enter a GA4 date such as “90daysAgo” or “2026-01-01”.');
    }

    /**
     * Catch an unusable service account key at save time rather than leaving it
     * to fail on the first report.
     */
    public function validateCredentials(string $attribute): void
    {
        if ($this->authMode !== self::AUTH_SERVICE_ACCOUNT) {
            return;
        }

        $value = $this->getCredentials();

        if ($value === '') {
            return;
        }

        try {
            ServiceAccountCredentials::resolve($value);
        } catch (AuthException $e) {
            $this->addError($attribute, $e->getMessage());
        }
    }

    /**
     * The same check for every per-site key, named so the editor knows which
     * site is at fault rather than being told "the credentials" are bad.
     */
    public function validateSiteCredentials(string $attribute): void
    {
        if ($this->authMode !== self::AUTH_SERVICE_ACCOUNT) {
            return;
        }

        foreach ($this->siteCredentials as $siteHandle => $credentials) {
            $value = trim((string)App::parseEnv((string)$credentials));

            if ($value === '') {
                continue;
            }

            try {
                ServiceAccountCredentials::resolve($value);
            } catch (AuthException $e) {
                $this->addError($attribute, "{$siteHandle}: {$e->getMessage()}");
            }
        }
    }

    public function validatePropertyId(string $attribute): void
    {
        $value = trim(App::parseEnv((string)$this->$attribute) ?: '');

        if ($value === '') {
            return;
        }

        if (str_starts_with($value, 'G-')) {
            $this->addError($attribute, 'That looks like a measurement ID. Telescope needs the numeric property ID from Admin → Property details.');

            return;
        }

        if (!Client::isValidPropertyId($value)) {
            $this->addError($attribute, 'A GA4 property ID is numeric, optionally prefixed with “properties/”.');
        }
    }

    // Resolved accessors — these parse environment variables
    // =========================================================================

    public function getCredentials(): string
    {
        return trim((string)App::parseEnv($this->credentials));
    }

    public function getClientId(): string
    {
        return trim((string)App::parseEnv($this->clientId));
    }

    public function getClientSecret(): string
    {
        return trim((string)App::parseEnv($this->clientSecret));
    }

    public function getRefreshToken(): string
    {
        return trim((string)App::parseEnv($this->refreshToken));
    }

    /**
     * The property ID to use for a site, falling back to the default.
     *
     * @param string|null $siteHandle the handle of the site being reported on
     */
    public function getPropertyIdForSite(?string $siteHandle = null): string
    {
        $value = $this->siteOverride($this->sitePropertyIds, $siteHandle)
            ?? trim((string)App::parseEnv($this->propertyId));

        return Client::normalizePropertyId($value);
    }

    /**
     * The service account to use for a site, falling back to the default.
     */
    public function getCredentialsForSite(?string $siteHandle = null): string
    {
        return $this->siteOverride($this->siteCredentials, $siteHandle) ?? $this->getCredentials();
    }

    /**
     * The refresh token to use for a site, falling back to the default.
     */
    public function getRefreshTokenForSite(?string $siteHandle = null): string
    {
        return $this->siteOverride($this->siteRefreshTokens, $siteHandle) ?? $this->getRefreshToken();
    }

    /**
     * Whether enough is configured to attempt a request at all.
     */
    public function isConfigured(?string $siteHandle = null): bool
    {
        if ($this->getPropertyIdForSite($siteHandle) === '') {
            return false;
        }

        return $this->authMode === self::AUTH_SERVICE_ACCOUNT
            ? $this->getCredentialsForSite($siteHandle) !== ''
            : $this->getClientId() !== ''
                && $this->getClientSecret() !== ''
                && $this->getRefreshTokenForSite($siteHandle) !== '';
    }

    /**
     * A site's override from one of the per-site maps, or null when it has
     * none. A key that is present but blank counts as "no override", so
     * clearing a field in the CP falls back rather than breaking the site.
     *
     * @param array<string, mixed> $overrides
     */
    private function siteOverride(array $overrides, ?string $siteHandle): ?string
    {
        if ($siteHandle === null || !isset($overrides[$siteHandle])) {
            return null;
        }

        $value = trim((string)App::parseEnv((string)$overrides[$siteHandle]));

        return $value !== '' ? $value : null;
    }

    /**
     * @return list<string>
     */
    public function getSections(): array
    {
        return ReportSection::normalize($this->sections);
    }

    /**
     * @return list<string>
     */
    public function getDashboardSections(): array
    {
        return DashboardSection::normalize($this->dashboardSections);
    }

    public function getCacheDuration(): int
    {
        return max(0, $this->cacheDuration);
    }

    public function getRowLimit(): int
    {
        return min(100, max(1, $this->rowLimit));
    }

    public function getWidgetLimit(): int
    {
        return min(50, max(1, $this->widgetLimit));
    }

    public function getDefaultPeriod(): Period
    {
        return Period::resolve($this->defaultPeriod);
    }

    /**
     * Whether an entry in the given section should get the automatic panel.
     */
    public function shouldAutoAttach(?string $sectionHandle): bool
    {
        if (!$this->autoAttachToEntries) {
            return false;
        }

        if ($this->autoAttachSections === []) {
            return true;
        }

        return $sectionHandle !== null && in_array($sectionHandle, $this->autoAttachSections, true);
    }
}
