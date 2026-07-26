<?php

declare(strict_types=1);

namespace justinholtweb\telescope;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\elements\Entry;
use craft\events\DefineHtmlEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\Dashboard;
use craft\services\Fields;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use justinholtweb\telescope\fields\AnalyticsField;
use justinholtweb\telescope\models\Settings;
use justinholtweb\telescope\services\Analytics;
use justinholtweb\telescope\twig\TelescopeVariable;
use justinholtweb\telescope\web\assets\cp\TelescopeAsset;
use justinholtweb\telescope\widgets\TopPagesWidget;
use yii\base\Event;

/**
 * Telescope — per-entry Google Analytics 4 reporting inside the Craft control panel.
 *
 * @method Settings getSettings()
 * @property-read Analytics $analytics
 */
class Plugin extends BasePlugin
{
    public const PERMISSION_VIEW = 'telescope:viewReports';

    public string $schemaVersion = '1.0.0';

    public bool $hasCpSettings = true;

    public bool $hasCpSection = true;

    public static function config(): array
    {
        return [
            'components' => [
                'analytics' => ['class' => Analytics::class],
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->registerFieldTypes();
        $this->registerWidgetTypes();
        $this->registerCpRoutes();
        $this->registerPermissions();
        $this->registerVariables();
        $this->registerEntrySidebar();
    }

    public function getAnalytics(): Analytics
    {
        /** @var Analytics $analytics */
        $analytics = $this->get('analytics');

        return $analytics;
    }

    public function getCpNavItem(): ?array
    {
        if (!Craft::$app->getUser()->checkPermission(self::PERMISSION_VIEW)) {
            return null;
        }

        $item = parent::getCpNavItem();
        $item['label'] = Craft::t('telescope', 'Telescope');
        $item['url'] = 'telescope';

        $item['subnav'] = [
            'overview' => [
                'label' => Craft::t('telescope', 'Overview'),
                'url' => 'telescope',
            ],
        ];

        if (Craft::$app->getUser()->getIsAdmin()) {
            $item['subnav']['settings'] = [
                'label' => Craft::t('telescope', 'Settings'),
                'url' => 'settings/plugins/telescope',
            ];
        }

        return $item;
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('telescope/_settings', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
        ]);
    }

    private function registerFieldTypes(): void
    {
        Event::on(
            Fields::class,
            Fields::EVENT_REGISTER_FIELD_TYPES,
            static function(RegisterComponentTypesEvent $event): void {
                $event->types[] = AnalyticsField::class;
            },
        );
    }

    private function registerWidgetTypes(): void
    {
        Event::on(
            Dashboard::class,
            Dashboard::EVENT_REGISTER_WIDGET_TYPES,
            static function(RegisterComponentTypesEvent $event): void {
                $event->types[] = TopPagesWidget::class;
            },
        );
    }

    private function registerCpRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            static function(RegisterUrlRulesEvent $event): void {
                $event->rules['telescope'] = 'telescope/reports/overview';
                $event->rules['telescope/entry/<elementId:\d+>'] = 'telescope/reports/entry';
                $event->rules['telescope/print/<elementId:\d+>'] = 'telescope/reports/print';
            },
        );
    }

    private function registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            static function(RegisterUserPermissionsEvent $event): void {
                $event->permissions[] = [
                    'heading' => Craft::t('telescope', 'Telescope'),
                    'permissions' => [
                        self::PERMISSION_VIEW => [
                            'label' => Craft::t('telescope', 'View analytics reports'),
                        ],
                    ],
                ];
            },
        );
    }

    private function registerVariables(): void
    {
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            static function(Event $event): void {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('telescope', TelescopeVariable::class);
            },
        );
    }

    /**
     * Optionally drop a compact analytics panel into every entry's sidebar, so
     * the plugin can be useful without editing a single field layout.
     */
    private function registerEntrySidebar(): void
    {
        Event::on(
            Entry::class,
            Entry::EVENT_DEFINE_SIDEBAR_HTML,
            function(DefineHtmlEvent $event): void {
                /** @var Entry $entry */
                $entry = $event->sender;

                if (!$this->getSettings()->shouldAutoAttach($entry->getSection()?->handle)) {
                    return;
                }

                if (!Craft::$app->getUser()->checkPermission(self::PERMISSION_VIEW)) {
                    return;
                }

                if (!$entry->id || $entry->getUrl() === null) {
                    return;
                }

                $view = Craft::$app->getView();
                $view->registerAssetBundle(TelescopeAsset::class);

                $event->html .= $view->renderTemplate('telescope/_components/sidebar', [
                    'element' => $entry,
                    'report' => $this->getAnalytics()->getReportForElement($entry),
                ]);
            },
        );
    }
}
