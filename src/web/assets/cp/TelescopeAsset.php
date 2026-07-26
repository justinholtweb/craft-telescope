<?php

declare(strict_types=1);

namespace justinholtweb\telescope\web\assets\cp;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * Control panel styles and the small amount of JS behind the chart tooltip,
 * period switcher and refresh button.
 */
class TelescopeAsset extends AssetBundle
{
    /**
     * Declared as constants so the contents can be asserted without booting
     * Craft — `craft\web\AssetBundle::init()` needs a running application.
     */
    public const SOURCE_PATH = __DIR__ . '/dist';

    /** @var list<string> */
    public const CSS = ['css/telescope.css'];

    /** @var list<string> */
    public const JS = ['js/telescope.js'];

    public function init(): void
    {
        $this->sourcePath = self::SOURCE_PATH;

        $this->depends = [
            CpAsset::class,
        ];

        $this->css = self::CSS;
        $this->js = self::JS;

        parent::init();
    }
}
