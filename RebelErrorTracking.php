<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelErrorTracking;

use Piwik\Common;
use Piwik\Plugin;
use Piwik\Plugins\RebelErrorTracking\Dao\LogError;

class RebelErrorTracking extends Plugin
{
    public function registerEvents()
    {
        return [
            'AssetManager.getStylesheetFiles' => 'getStylesheetFiles',
            'Db.getTablesInstalled' => 'getTablesInstalled',
            'Metrics.getDefaultMetricTranslations' => 'addMetricTranslations',
        ];
    }

    public function isTrackerPlugin()
    {
        return true;
    }

    public function install()
    {
        (new LogError())->install();
    }

    public function uninstall()
    {
        (new LogError())->uninstall();
    }

    public function getStylesheetFiles(&$stylesheets)
    {
        $stylesheets[] = 'plugins/RebelErrorTracking/stylesheets/menuIcon.less';
        $stylesheets[] = 'plugins/RebelErrorTracking/stylesheets/reports.less';
    }

    public function addMetricTranslations(&$translations)
    {
        $translations['nb_errors'] = 'RebelErrorTracking_ColumnErrors';
        $translations['nb_visits_with_errors'] = 'RebelErrorTracking_ColumnVisitsWithErrors';
    }

    public function getTablesInstalled(&$tables)
    {
        $tables[] = Common::prefixTable(LogError::TABLE);
    }
}
