<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelErrorTracking;

use Piwik\Date;
use Piwik\Plugins\RebelErrorTracking\Dao\LogError;

class Tasks extends \Piwik\Plugin\Tasks
{
    public function schedule()
    {
        $this->daily('deleteOldErrors', null, self::LOW_PRIORITY);
    }

    public function deleteOldErrors(): void
    {
        $days = (new SystemSettings())->getRetentionDays();

        (new LogError())->deleteOlderThan(Date::now()->subDay($days)->getDatetime());
    }
}
