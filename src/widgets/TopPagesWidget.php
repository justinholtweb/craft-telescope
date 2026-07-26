<?php

declare(strict_types=1);

namespace justinholtweb\telescope\widgets;

use Craft;
use craft\base\Widget;
use justinholtweb\telescope\ga4\Period;
use justinholtweb\telescope\Plugin;

/**
 * A dashboard widget listing the property's most-viewed pages, with a link
 * through to the matching entry where one exists.
 */
class TopPagesWidget extends Widget
{
    /**
     * Period preset handle, or empty to use the plugin default.
     */
    public string $period = '';

    /**
     * Rows to show, or null to use the plugin default.
     */
    public ?int $limit = null;

    /**
     * The site to report on, or null for the primary site.
     */
    public ?int $siteId = null;

    public static function displayName(): string
    {
        return Craft::t('telescope', 'Top pages');
    }

    public static function icon(): ?string
    {
        return 'chart-line';
    }

    public static function maxColspan(): ?int
    {
        return 2;
    }

    public function getTitle(): ?string
    {
        return Craft::t('telescope', 'Top pages');
    }

    public function getSubtitle(): ?string
    {
        return $this->resolvePeriod()->label;
    }

    /**
     * @return array<int, mixed>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            [['limit'], 'integer', 'min' => 1, 'max' => 50],
            [['siteId'], 'integer'],
            [['period'], 'safe'],
        ]);
    }

    public function getBodyHtml(): ?string
    {
        if (!Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_VIEW)) {
            return Craft::$app->getView()->renderTemplate('telescope/_components/notice', [
                'message' => Craft::t('telescope', 'You do not have permission to view analytics.'),
                'type' => 'info',
            ]);
        }

        $plugin = Plugin::getInstance();

        if ($plugin === null || !$plugin->getSettings()->isConfigured()) {
            return Craft::$app->getView()->renderTemplate('telescope/_components/notice', [
                'message' => Craft::t('telescope', 'Telescope has not been connected to Google Analytics yet.'),
                'type' => 'info',
            ]);
        }

        $period = $this->resolvePeriod();
        $pages = $plugin->getAnalytics()->getTopPages($this->siteId, $period, $this->limit);

        return Craft::$app->getView()->renderTemplate('telescope/_components/widget', [
            'pages' => $pages,
            'period' => $period,
            'siteId' => $this->siteId,
        ]);
    }

    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('telescope/_components/widget-settings', [
            'widget' => $this,
            'periodOptions' => ['' => Craft::t('telescope', 'Plugin default')] + Period::presetOptions(),
        ]);
    }

    private function resolvePeriod(): Period
    {
        if ($this->period !== '') {
            return Period::resolve($this->period);
        }

        $plugin = Plugin::getInstance();

        return $plugin !== null ? $plugin->getSettings()->getDefaultPeriod() : Period::preset('last28days');
    }
}
