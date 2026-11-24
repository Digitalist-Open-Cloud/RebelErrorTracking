<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelErrorTracking\RecordBuilders;

use Piwik\ArchiveProcessor;
use Piwik\ArchiveProcessor\Record;
use Piwik\ArchiveProcessor\RecordBuilder;
use Piwik\DataTable;
use Piwik\DataTable\Row;
use Piwik\Plugins\RebelErrorTracking\Dao\LogError;

/**
 * The errors of a period: one row for each fingerprint, the errors of each browser, and the totals.
 */
class Errors extends RecordBuilder
{
    public const RECORD = 'RebelErrorTracking_errors';
    public const BROWSERS = 'RebelErrorTracking_browsers';
    public const NB_ERRORS = 'RebelErrorTracking_nb_errors';
    public const NB_VISITS = 'RebelErrorTracking_nb_visits_with_errors';

    private const MAX_ROWS = 500;

    public function __construct()
    {
        parent::__construct(self::MAX_ROWS, null, 'nb_errors', ['last_seen' => 'max', 'max_idlog' => 'max']);
    }

    public function getRecordMetadata(ArchiveProcessor $archiveProcessor): array
    {
        return [
            Record::make(Record::TYPE_BLOB, self::RECORD),
            Record::make(Record::TYPE_BLOB, self::BROWSERS),
            Record::make(Record::TYPE_NUMERIC, self::NB_ERRORS),
            Record::make(Record::TYPE_NUMERIC, self::NB_VISITS),
        ];
    }

    protected function aggregate(ArchiveProcessor $archiveProcessor): array
    {
        $logAggregator = $archiveProcessor->getLogAggregator();
        $log = LogError::TABLE;
        $from = [$log];
        $where = $logAggregator->getWhereStatement($log, 'server_time');

        $query = $logAggregator->generateQuery(
            "$log.fingerprint AS label, MIN($log.type) AS type, MIN($log.message) AS message, MIN($log.source) AS source,
             COUNT(*) AS nb_errors, COUNT(DISTINCT $log.idvisit) AS nb_visits,
             MAX($log.server_time) AS last_seen, MAX($log.idlog) AS max_idlog",
            $from,
            $where,
            "$log.fingerprint",
            'nb_errors DESC'
        );

        $table = new DataTable();
        foreach ($logAggregator->getDb()->fetchAll($query['sql'], $query['bind']) as $group) {
            $table->addRow(new Row([
                Row::COLUMNS => [
                    'label' => $group['label'],
                    'nb_errors' => (int) $group['nb_errors'],
                    'nb_visits' => (int) $group['nb_visits'],
                    // A number, so that the latest of several days can be taken.
                    'last_seen' => strtotime($group['last_seen'] . ' UTC'),
                    'max_idlog' => (int) $group['max_idlog'],
                ],
                Row::METADATA => [
                    'type' => $group['type'],
                    'message' => $group['message'],
                    'source' => $group['source'],
                ],
            ]));
        }

        $query = $logAggregator->generateQuery(
            "COUNT(*) AS nb_errors, COUNT(DISTINCT $log.idvisit) AS nb_visits",
            $from,
            $where,
            false,
            false
        );
        $totals = $logAggregator->getDb()->fetchRow($query['sql'], $query['bind']);

        // The browser is known from the visit an error belongs to.
        $query = $logAggregator->generateQuery(
            "CONCAT(log_visit.config_browser_name, ';', log_visit.config_browser_version) AS label,
             COUNT(*) AS nb_errors, COUNT(DISTINCT $log.idvisit) AS nb_visits",
            [$log, 'log_visit'],
            $where,
            'label',
            'nb_errors DESC'
        );
        $browsers = new DataTable();
        foreach ($logAggregator->getDb()->fetchAll($query['sql'], $query['bind']) as $browser) {
            $browsers->addRowFromSimpleArray([
                // Without the visit, the browser is not known.
                'label' => $browser['label'] ?? 'UNK;',
                'nb_errors' => (int) $browser['nb_errors'],
                'nb_visits' => (int) $browser['nb_visits'],
            ]);
        }

        return [
            self::RECORD => $table,
            self::BROWSERS => $browsers,
            self::NB_ERRORS => (int) ($totals['nb_errors'] ?? 0),
            self::NB_VISITS => (int) ($totals['nb_visits'] ?? 0),
        ];
    }
}
