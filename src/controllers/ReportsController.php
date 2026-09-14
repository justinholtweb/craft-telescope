<?php

declare(strict_types=1);

namespace justinholtweb\telescope\controllers;

use Craft;
use craft\elements\Entry;
use craft\web\Controller;
use DateTimeImmutable;
use DateTimeZone;
use justinholtweb\telescope\ga4\Period;
use justinholtweb\telescope\Plugin;
use justinholtweb\telescope\reports\SiteReport;
use justinholtweb\telescope\web\assets\cp\TelescopeReportAsset;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Control panel reporting screens.
 */
class ReportsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        $this->requirePermission(Plugin::PERMISSION_VIEW);

        return true;
    }

    /**
     * The site-wide dashboard: totals, charts and top pages for the selected
     * site and period.
     */
    public function actionOverview(): Response
    {
        $request = Craft::$app->getRequest();
        $period = Period::resolve($request->getParam('period'), $this->defaultPeriodHandle());
        $siteId = $this->resolveSiteId($request->getParam('siteId'));
        $plugin = $this->plugin();

        $isConfigured = $plugin->getSettings()->isConfigured();

        // The dashboard draws charts, so it needs Chart.js — the compact bundle
        // does not carry it.
        $this->view->registerAssetBundle(TelescopeReportAsset::class);

        return $this->renderTemplate('telescope/overview', [
            'report' => $isConfigured
                ? $plugin->getAnalytics()->getSiteReport($siteId, $period)
                : SiteReport::empty($period->label),
            'period' => $period,
            'periodOptions' => Period::presetOptions(),
            'siteId' => $siteId,
            'sites' => Craft::$app->getSites()->getEditableSites(),
            'isConfigured' => $isConfigured,
        ]);
    }

    /**
     * A single entry's report, rendered standalone.
     */
    public function actionEntry(int $elementId): Response
    {
        $entry = $this->findEntry($elementId);
        $period = Period::resolve(Craft::$app->getRequest()->getParam('period'), $this->defaultPeriodHandle());

        $this->view->registerAssetBundle(TelescopeReportAsset::class);

        return $this->renderTemplate('telescope/entry', [
            'element' => $entry,
            'report' => $this->plugin()->getAnalytics()->getReportForElement($entry, $period),
            'period' => $period,
            'periodOptions' => Period::presetOptions(),
        ]);
    }

    /**
     * A print-optimised report — the route behind the "Print / Save as PDF"
     * button. Rendering it as a plain page means no PDF library, no CDN
     * download, and an editor's browser does the export.
     */
    public function actionPrint(int $elementId): Response
    {
        $entry = $this->findEntry($elementId);
        $period = Period::resolve(Craft::$app->getRequest()->getParam('period'), $this->defaultPeriodHandle());

        return $this->renderTemplate('telescope/print', [
            'element' => $entry,
            'report' => $this->plugin()->getAnalytics()->getReportForElement($entry, $period),
            'period' => $period,
            'siteName' => $entry->getSite()->name,
            'generatedAt' => new DateTimeImmutable('now', new DateTimeZone(Craft::$app->getTimeZone())),
            // Inlined rather than loaded as an asset bundle: the print view is a
            // standalone document, and going through the CP's head/body hooks
            // would let every other plugin inject its own chrome into the PDF.
            'css' => self::printStyles(),
        ]);
    }

    /**
     * Re-fetch one report, bypassing the cache. Used by the refresh button.
     */
    public function actionRefresh(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $elementId = (int)$request->getRequiredBodyParam('elementId');
        $entry = $this->findEntry($elementId);
        $period = Period::resolve($request->getBodyParam('period'), $this->defaultPeriodHandle());

        $report = $this->plugin()->getAnalytics()->getReportForElement($entry, $period, useCache: false);

        return $this->asJson([
            'success' => !$report->hasErrors(),
            'report' => $report->toArray(),
            'html' => $this->getView()->renderTemplate('telescope/_components/report', [
                'report' => $report,
                'element' => $entry,
                'entryUrl' => $entry->getUrl(),
                'printUrl' => null,
            ]),
        ]);
    }

    /**
     * Empty every cached report.
     */
    public function actionClearCache(): Response
    {
        $this->requirePostRequest();

        $this->plugin()->getAnalytics()->clearCache();

        if ($this->request->getAcceptsJson()) {
            return $this->asSuccess(Craft::t('telescope', 'Analytics cache cleared.'));
        }

        return $this->asSuccess(Craft::t('telescope', 'Analytics cache cleared.'), redirect: 'telescope');
    }

    /**
     * Check credentials and property access from the settings screen.
     */
    public function actionTestConnection(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requireAdmin(false);

        $status = $this->plugin()->getAnalytics()->testConnection(
            $this->resolveSiteId(Craft::$app->getRequest()->getBodyParam('siteId')),
        );

        return $this->asJson($status->toArray());
    }

    /**
     * The plugin's control panel stylesheet, read straight off disk.
     */
    private static function printStyles(): string
    {
        $path = dirname(__DIR__) . '/web/assets/cp/dist/css/telescope.css';
        $css = is_readable($path) ? file_get_contents($path) : false;

        return $css !== false ? $css : '';
    }

    private function plugin(): Plugin
    {
        $plugin = Plugin::getInstance();

        if ($plugin === null) {
            throw new NotFoundHttpException('Telescope is not installed.');
        }

        return $plugin;
    }

    /**
     * @throws NotFoundHttpException if no such entry exists in the requested site
     * @throws ForbiddenHttpException if the user cannot view the entry's site
     */
    private function findEntry(int $elementId): Entry
    {
        $siteId = $this->resolveSiteId(Craft::$app->getRequest()->getParam('siteId'));

        $entry = Entry::find()
            ->id($elementId)
            ->siteId($siteId)
            ->status(null)
            ->drafts(null)
            ->revisions(null)
            ->one();

        if (!$entry instanceof Entry) {
            throw new NotFoundHttpException('Entry not found.');
        }

        return $entry;
    }

    private function resolveSiteId(mixed $siteId): int
    {
        $sites = Craft::$app->getSites();
        $siteId = is_numeric($siteId) ? (int)$siteId : null;

        if ($siteId === null) {
            return $sites->getCurrentSite()->id;
        }

        $site = $sites->getSiteById($siteId);

        if ($site === null) {
            throw new NotFoundHttpException('Site not found.');
        }

        if (!Craft::$app->getUser()->checkPermission("editSite:{$site->uid}")) {
            throw new ForbiddenHttpException('You are not allowed to view analytics for this site.');
        }

        return $site->id;
    }

    private function defaultPeriodHandle(): string
    {
        $default = $this->plugin()->getSettings()->defaultPeriod;

        return $default !== '' ? $default : 'last28days';
    }
}
