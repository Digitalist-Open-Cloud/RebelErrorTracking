<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelErrorTracking;

use Exception;
use Piwik\Common;
use Piwik\Piwik;
use Piwik\Plugins\RebelErrorTracking\Tracker\ErrorReport;
use Piwik\Settings\FieldConfig;
use Piwik\Settings\Setting;

class MeasurableSettings extends \Piwik\Settings\Measurable\MeasurableSettings
{
    /**
     * @var Setting
     */
    public $enabled;

    /**
     * @var Setting
     */
    public $browserWarnings;

    /**
     * @var Setting
     */
    public $ignored;

    private const MAX_IGNORED = 50;

    protected function init()
    {
        $this->enabled = $this->makeSetting('enabled', true, FieldConfig::TYPE_BOOL, function (FieldConfig $field) {
            $field->title = Piwik::translate('RebelErrorTracking_SettingEnabled');
            $field->inlineHelp = Piwik::translate('RebelErrorTracking_SettingEnabledHelp');
            $field->uiControl = FieldConfig::UI_CONTROL_CHECKBOX;
        });

        $this->browserWarnings = $this->makeSetting('browser_warnings', false, FieldConfig::TYPE_BOOL, function (FieldConfig $field) {
            $field->title = Piwik::translate('RebelErrorTracking_SettingBrowserWarnings');
            $field->inlineHelp = Piwik::translate('RebelErrorTracking_SettingBrowserWarningsHelp');
            $field->uiControl = FieldConfig::UI_CONTROL_CHECKBOX;
        });

        $this->ignored = $this->makeSetting('ignored', '', FieldConfig::TYPE_STRING, function (FieldConfig $field) {
            $field->title = Piwik::translate('RebelErrorTracking_SettingIgnored');
            $field->inlineHelp = Piwik::translate('RebelErrorTracking_SettingIgnoredHelp');
            $field->uiControl = FieldConfig::UI_CONTROL_TEXTAREA;
            $field->validate = function ($value) {
                if (count(self::lines((string) $value)) > self::MAX_IGNORED) {
                    throw new Exception(Piwik::translate('RebelErrorTracking_ErrorTooManyIgnored', [self::MAX_IGNORED]));
                }
            };
        });
    }

    /**
     * Whether an error with these stored columns is to be kept for the website.
     *
     * @param array<string, int|string> $error
     */
    public function accepts(array $error): bool
    {
        if (!$this->enabled->getValue()) {
            return false;
        }

        if ($error['type'] === ErrorReport::TYPE_BROWSER && !$this->browserWarnings->getValue()) {
            return false;
        }

        // The stored texts are encoded for HTML, so what is typed in the setting is compared decoded.
        $texts = Common::unsanitizeInputValue($error['message'] . "\n" . $error['source']);
        foreach (self::lines((string) $this->ignored->getValue()) as $ignored) {
            if (mb_stripos($texts, Common::unsanitizeInputValue($ignored)) !== false) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return string[]
     */
    private static function lines(string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', $text))));
    }
}
