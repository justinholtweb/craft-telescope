<?php

declare(strict_types=1);

namespace justinholtweb\telescope\web\assets\cp;

use craft\web\AssetBundle;

/**
 * The libraries the full report needs: an interactive timeline chart and
 * client-side PDF export.
 *
 * Kept separate from {@see TelescopeAsset} because it is around half a megabyte
 * of JavaScript — only screens that actually draw a report should pay for it,
 * not every entry that shows the compact sidebar panel.
 *
 * Versions are pinned deliberately: Chart.js 4.4 and chartjs-plugin-zoom 2.0
 * are a known-good pairing, and an unpinned CDN URL is a silent breakage
 * waiting to happen on somebody else's site.
 */
class TelescopeReportAsset extends AssetBundle
{
    public const CHART_JS = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js';
    public const HAMMER_JS = 'https://cdn.jsdelivr.net/npm/hammerjs@2.0.8/hammer.min.js';
    public const CHART_ZOOM_JS = 'https://cdn.jsdelivr.net/npm/chartjs-plugin-zoom@2.0.1/dist/chartjs-plugin-zoom.min.js';
    public const HTML2CANVAS_JS = 'https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js';
    public const JSPDF_JS = 'https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js';

    /**
     * Order matters: the zoom plugin registers itself against Chart, and needs
     * Hammer for pan and pinch gestures.
     *
     * @var list<string>
     */
    public const JS = [
        self::CHART_JS,
        self::HAMMER_JS,
        self::CHART_ZOOM_JS,
        self::HTML2CANVAS_JS,
        self::JSPDF_JS,
    ];

    public function init(): void
    {
        $this->depends = [
            TelescopeAsset::class,
        ];

        $this->js = self::JS;

        parent::init();
    }
}
