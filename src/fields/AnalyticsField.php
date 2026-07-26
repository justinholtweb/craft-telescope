<?php

declare(strict_types=1);

namespace justinholtweb\telescope\fields;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\elements\Entry;
use craft\helpers\UrlHelper;
use justinholtweb\telescope\ga4\Period;
use justinholtweb\telescope\Plugin;
use justinholtweb\telescope\web\assets\cp\TelescopeReportAsset;

/**
 * A read-only field that renders a full analytics report for the element it is
 * attached to.
 *
 * Stores nothing: `dbType()` returns null, so adding or removing it never
 * touches content tables, and it can be dropped into any field layout whose
 * elements have public URLs.
 */
class AnalyticsField extends Field
{
    /**
     * Period preset handle, or an empty string to use the plugin default.
     */
    public string $period = '';

    /**
     * Hide the report when the element has no recorded traffic, instead of
     * showing a row of zeroes.
     */
    public bool $hideWhenEmpty = false;

    public static function displayName(): string
    {
        return Craft::t('telescope', 'Analytics');
    }

    public static function icon(): string
    {
        return 'chart-line';
    }

    public static function isRequirable(): bool
    {
        return false;
    }

    public static function dbType(): array|string|null
    {
        // Nothing is persisted — the report is fetched live and cached.
        return null;
    }

    public static function queryCondition(array $instances, mixed $value, array &$params): array|string|false
    {
        return false;
    }

    public function useFieldset(): bool
    {
        return true;
    }

    public function getIsTranslatable(?ElementInterface $element = null): bool
    {
        return false;
    }

    public function normalizeValue(mixed $value, ?ElementInterface $element = null): mixed
    {
        return null;
    }

    public function serializeValue(mixed $value, ?ElementInterface $element = null): mixed
    {
        return null;
    }

    public function isValueEmpty(mixed $value, ElementInterface $element): bool
    {
        return true;
    }

    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('telescope/_components/field-settings', [
            'field' => $this,
            'periodOptions' => ['' => Craft::t('telescope', 'Plugin default')] + Period::presetOptions(),
        ]);
    }

    protected function inputHtml(mixed $value, ?ElementInterface $element, bool $inline): string
    {
        $view = Craft::$app->getView();

        if (!$element instanceof ElementInterface) {
            return $this->notice(Craft::t('telescope', 'Analytics are only available on elements.'));
        }

        if (!$element->id) {
            return $this->notice(Craft::t('telescope', 'Save this entry first to see its analytics.'));
        }

        if (!Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_VIEW)) {
            return $this->notice(Craft::t('telescope', 'You do not have permission to view analytics.'));
        }

        if ($element->getUrl() === null) {
            return $this->notice(Craft::t('telescope', 'This entry has no public URL, so there is no page for Google Analytics to have recorded.'));
        }

        $plugin = Plugin::getInstance();

        if ($plugin === null) {
            return $this->notice(Craft::t('telescope', 'Telescope is not available.'));
        }

        $report = $plugin->getAnalytics()->getReportForElement($element, $this->resolvePeriod());

        if ($this->hideWhenEmpty && !$report->hasData() && !$report->hasErrors()) {
            return '';
        }

        $view->registerAssetBundle(TelescopeReportAsset::class);

        return $view->renderTemplate('telescope/_components/report', [
            'report' => $report,
            'element' => $element,
            'entryUrl' => $element->getUrl(),
            'printUrl' => $element instanceof Entry
                ? UrlHelper::cpUrl("telescope/print/{$element->id}", [
                    'siteId' => $element->siteId,
                    'period' => $this->period ?: null,
                ])
                : null,
        ]);
    }

    public function getPreviewHtml(mixed $value, ElementInterface $element): string
    {
        return '';
    }

    /**
     * @return array<int, mixed>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            [['period'], 'validatePeriod'],
            [['hideWhenEmpty'], 'boolean'],
        ]);
    }

    public function validatePeriod(string $attribute): void
    {
        $value = (string)$this->$attribute;

        if ($value === '' || isset(Period::presetOptions()[$value]) || Period::isValidDate($value)) {
            return;
        }

        $this->addError($attribute, Craft::t('telescope', 'Choose a valid reporting period.'));
    }

    private function resolvePeriod(): ?Period
    {
        return $this->period === '' ? null : Period::resolve($this->period);
    }

    private function notice(string $message): string
    {
        return Craft::$app->getView()->renderTemplate('telescope/_components/notice', [
            'message' => $message,
            'type' => 'info',
        ]);
    }
}
