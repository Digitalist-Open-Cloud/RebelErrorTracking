<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelErrorTracking;

use Piwik\Common;
use Piwik\Plugins\RebelErrorTracking\Dao\LogError;
use Piwik\Updater;
use Piwik\Updater\Migration\Factory as MigrationFactory;
use Piwik\Updates as PiwikUpdates;

/**
 * Adds the table of when each error was first seen and fills it from the stored errors.
 */
class Updates_5_1_0 extends PiwikUpdates
{
    private MigrationFactory $migration;

    public function __construct(MigrationFactory $factory)
    {
        $this->migration = $factory;
    }

    public function getMigrations(Updater $updater)
    {
        $errors = Common::prefixTable(LogError::TABLE);
        $firstSeen = Common::prefixTable(LogError::FIRST_SEEN_TABLE);

        return [
            $this->migration->db->addIndex(LogError::TABLE, ['idsite', 'fingerprint'], 'site_fingerprint'),
            $this->migration->db->sql(
                "INSERT IGNORE INTO $firstSeen (idsite, fingerprint, first_seen)
                 SELECT idsite, fingerprint, MIN(server_time) FROM $errors GROUP BY idsite, fingerprint"
            ),
        ];
    }

    public function doUpdate(Updater $updater)
    {
        (new LogError())->installFirstSeen();
        $updater->executeMigrations(__FILE__, $this->getMigrations($updater));
    }
}
