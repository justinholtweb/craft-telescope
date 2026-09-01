<?php

declare(strict_types=1);

namespace justinholtweb\telescope\console\controllers;

use Craft;
use craft\console\Controller;
use craft\elements\Entry;
use craft\helpers\Console;
use craft\models\Site;
use justinholtweb\telescope\ga4\Period;
use justinholtweb\telescope\helpers\Format;
use justinholtweb\telescope\Plugin;
use justinholtweb\telescope\services\Analytics;
use yii\console\ExitCode;

/**
 * Telescope analytics commands.
 */
class AnalyticsController extends Controller
{
    /**
     * The reporting period — a preset handle (`last28days`) or a GA4 start date
     * (`90daysAgo`, `2026-01-01`).
     */
    public string $period = '';

    /**
     * The site handle to report on.
     */
    public ?string $site = null;

    /**
     * Maximum entries to process.
     */
    public int $limit = 25;

    /**
     * @return list<string>
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);

        return match ($actionID) {
            'check' => [...$options, 'site'],
            'show' => [...$options, 'period', 'site'],
            'top-pages', 'warm' => [...$options, 'period', 'site', 'limit'],
            default => $options,
        };
    }

    /**
     * Check that credentials, the property ID and API access all work.
     *
     * Sites can have their own credentials and their own property, so with no
     * `--site` this checks every one of them: a single verdict for the primary
     * site would hide a second site whose Google account has lost access.
     */
    public function actionCheck(): int
    {
        $sites = $this->sitesToCheck();
        $failed = false;

        foreach ($sites as $site) {
            if (count($sites) > 1) {
                $this->stdout("\n{$site->name}\n", Console::FG_YELLOW);
            }

            $status = $this->analytics()->testConnection($site->id);

            foreach ($status->checks as $check) {
                $this->stdout("  ✓ {$check}\n", Console::FG_GREEN);
            }

            if (!$status->ok) {
                $failed = true;
                $this->stderr("\n✗ {$status->message}\n", Console::FG_RED);

                continue;
            }

            $this->stdout("\n✓ {$status->message}\n", Console::FG_GREEN);
        }

        $this->stdout("\n");

        return $failed ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    /**
     * The sites `check` should run against: the one named by `--site`, or all
     * of them.
     *
     * @return list<Site>
     */
    private function sitesToCheck(): array
    {
        $sites = Craft::$app->getSites();
        $siteId = $this->siteId();

        if ($siteId !== null) {
            $site = $sites->getSiteById($siteId);

            return $site !== null ? [$site] : [];
        }

        return array_values($sites->getAllSites());
    }

    /**
     * Show the report for one entry.
     *
     * @param string $identifier an entry ID, or a URL/path such as `/about`
     */
    public function actionShow(string $identifier): int
    {
        $period = Period::resolve($this->period, $this->defaultPeriod());
        $siteId = $this->siteId();

        if (ctype_digit($identifier)) {
            $entry = Entry::find()->id((int)$identifier)->siteId($siteId)->status(null)->one();

            if (!$entry instanceof Entry) {
                $this->stderr("✗ No entry found with ID {$identifier}.\n", Console::FG_RED);

                return ExitCode::DATAERR;
            }

            $this->stdout("{$entry->title}\n", Console::FG_CYAN);
            $report = $this->analytics()->getReportForElement($entry, $period, useCache: false);
        } else {
            $report = $this->analytics()->getReport($identifier, $siteId, $period, useCache: false);
        }

        $this->stdout("{$report->path} — {$report->periodLabel}\n\n");

        foreach ($report->overview->cards() as $card) {
            $this->stdout(sprintf("  %-18s %s\n", $card['label'], $card['value']));
        }

        if ($report->timeline !== []) {
            $busiest = $report->busiestDay();
            $this->stdout("\n  Busiest day       {$busiest['date']} ({$busiest['views']} views)\n");
        }

        foreach ($report->errors as $error) {
            $this->stderr("\n  ! {$error}\n", Console::FG_YELLOW);
        }

        $this->stdout("\n");

        return $report->hasErrors() ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    /**
     * List the property's most-viewed pages.
     */
    public function actionTopPages(): int
    {
        $period = Period::resolve($this->period, $this->defaultPeriod());
        $pages = $this->analytics()->getTopPages($this->siteId(), $period, $this->limit, useCache: false);

        if ($pages === []) {
            $this->stdout("No data returned for {$period->label}.\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        $this->stdout("Top pages — {$period->label}\n\n", Console::FG_CYAN);

        foreach ($pages as $page) {
            $this->stdout(sprintf(
                "  %8s  %8s  %s\n",
                Format::compact($page['views']),
                Format::compact($page['users']),
                $page['path'],
            ));
        }

        $this->stdout("\n  views     users     path\n\n", Console::FG_GREY);

        return ExitCode::OK;
    }

    /**
     * Pre-fetch reports so editors never wait on a cold cache.
     *
     * @param string|null $section limit to one section handle
     */
    public function actionWarm(?string $section = null): int
    {
        $period = Period::resolve($this->period, $this->defaultPeriod());
        $siteId = $this->siteId();

        $query = Entry::find()->siteId($siteId)->limit($this->limit);

        if ($section !== null) {
            $query->section($section);
        }

        $entries = $query->all();
        $warmed = 0;
        $skipped = 0;

        $this->stdout('Warming ' . count($entries) . " entries for {$period->label}…\n");

        foreach ($entries as $entry) {
            if ($entry->getUrl() === null) {
                $skipped++;
                continue;
            }

            $report = $this->analytics()->getReportForElement($entry, $period, useCache: false);

            if ($report->hasErrors()) {
                $this->stderr("  ! {$entry->title}: " . implode('; ', $report->errors) . "\n", Console::FG_YELLOW);
                continue;
            }

            $warmed++;
            $this->stdout(sprintf("  ✓ %-50s %s views\n", mb_strimwidth((string)$entry->title, 0, 50, '…'), Format::number($report->overview->views)));

            // GA4 allows generous per-property quotas, but a tight loop over a
            // few hundred entries is exactly what trips the per-minute limit.
            usleep(150000);
        }

        $this->stdout("\n✓ Warmed {$warmed} report(s)");
        $this->stdout($skipped > 0 ? ", skipped {$skipped} without URLs.\n\n" : ".\n\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Clear every cached report.
     */
    public function actionClearCache(): int
    {
        $this->analytics()->clearCache();
        $this->stdout("✓ Telescope cache cleared.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    private function analytics(): Analytics
    {
        $plugin = Plugin::getInstance();

        if ($plugin === null) {
            throw new \RuntimeException('Telescope is not installed.');
        }

        return $plugin->getAnalytics();
    }

    private function defaultPeriod(): string
    {
        return Plugin::getInstance()?->getSettings()->defaultPeriod ?? Period::DEFAULT_PRESET;
    }

    private function siteId(): ?int
    {
        if ($this->site === null) {
            return null;
        }

        $site = Craft::$app->getSites()->getSiteByHandle($this->site);

        if ($site === null) {
            throw new \RuntimeException("No site found with the handle “{$this->site}”.");
        }

        return $site->id;
    }
}
