<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelErrorTracking\Tracker;

use Piwik\Plugins\RebelErrorTracking\Dao\LogError;
use Piwik\Tracker\LogTable;

class ErrorLogTable extends LogTable
{
    public function getName()
    {
        return LogError::TABLE;
    }

    public function getIdColumn()
    {
        return 'idlog';
    }

    public function getPrimaryKey()
    {
        return ['idlog'];
    }

    public function getColumnToJoinOnIdVisit()
    {
        return 'idvisit';
    }

    public function getDateTimeColumn()
    {
        return 'server_time';
    }

    public function hasIdVisitorColumn(): bool
    {
        return true;
    }

    public function shouldJoinWithSubSelect()
    {
        return true;
    }
}
