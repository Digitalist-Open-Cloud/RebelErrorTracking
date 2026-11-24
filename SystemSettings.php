<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelErrorTracking;

use Exception;
use Piwik\Piwik;
use Piwik\Settings\FieldConfig;

class SystemSettings extends \Piwik\Settings\Plugin\SystemSettings
{
    public const DEFAULT_RETENTION_DAYS = 90;

    private const MAX_RETENTION_DAYS = 3650;

    protected function init()
    {
        $this->makeSetting('retention_days', self::DEFAULT_RETENTION_DAYS, FieldConfig::TYPE_INT, function (FieldConfig $field) {
            $field->title = Piwik::translate('RebelErrorTracking_SettingRetention');
            $field->inlineHelp = Piwik::translate('RebelErrorTracking_SettingRetentionHelp');
            $field->uiControl = FieldConfig::UI_CONTROL_TEXT;
            $field->validate = function ($value) {
                if (!is_numeric($value) || $value < 1 || $value > self::MAX_RETENTION_DAYS) {
                    throw new Exception(Piwik::translate('RebelErrorTracking_ErrorRetentionRange', [1, self::MAX_RETENTION_DAYS]));
                }
            };
        });
    }

    public function getRetentionDays(): int
    {
        $value = (int) $this->getSetting('retention_days')->getValue();

        return $value >= 1 ? $value : self::DEFAULT_RETENTION_DAYS;
    }
}
