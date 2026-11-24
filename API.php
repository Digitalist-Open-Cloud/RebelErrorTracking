<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelErrorTracking;

use Piwik\Archive;
use Piwik\Common;
use Piwik\DataTable;
use Piwik\DataTable\Row;
use Piwik\Date;
use Piwik\Period;
use Piwik\Period\Factory;
use Piwik\Period\Range;
use Piwik\Piwik;
use Piwik\Plugins\RebelErrorTracking\Dao\LogError;
use Piwik\Plugins\RebelErrorTracking\RecordBuilders\Errors;
use Piwik\Site;

require_once PIWIK_INCLUDE_PATH . '/plugins/DevicesDetection/functions.php';

/**
 * The errors that happened in the browsers of the visitors of a website.
 *
 * @method static \Piwik\Plugins\RebelErrorTracking\API getInstance()
 */
class API extends \Piwik\Plugin\API
{
    private const MAX_OCCURRENCES = 20;
    private const MAX_BROWSERS_SHOWN = 3;

    /**
     * The totals of a period: the errors, the errors that were seen for the first time, the visits
     * with an error, and the share of all visits they are.
     *
     * @param int|string $idSite
     * @param string $period
     * @param string $date
     * @param string|false $segment
     * @param string|false $columns
     * @return DataTable|DataTable\Map
     */
    public function get($idSite, $period, $date, $segment = false, $columns = false)
    {
        Piwik::checkUserHasViewAccess($idSite);

        $table = Archive::build($idSite, $period, $date, $segment)
            ->getDataTableFromNumeric([Errors::NB_ERRORS, Errors::NB_NEW_ERRORS, Errors::NB_VISITS, 'nb_visits']);
        $table->filter('ReplaceColumnNames', [[
            Errors::NB_ERRORS => 'nb_errors',
            Errors::NB_NEW_ERRORS => 'nb_new_errors',
            Errors::NB_VISITS => 'nb_visits_with_errors',
        ]]);
        $table->filter('ColumnCallbackAddColumnPercentage', ['error_rate', 'nb_visits_with_errors', 'nb_visits', 1]);
        $table->filter('ColumnDelete', [['nb_visits']]);

        $columns = Piwik::getArrayFromApiParameter($columns);
        if ($columns) {
            $table->filter('ColumnDelete', [[], $columns]);
        }

        return $table;
    }

    /**
     * The errors of a period, the same error counted as one row, with the browsers it happened most in
     * and when it was first seen. `is_new` is 1 for an error that was first seen in the period.
     *
     * @param int|string $idSite
     * @param string $period
     * @param string $date
     * @param string|false $segment
     * @return DataTable|DataTable\Map
     */
    public function getErrors($idSite, $period, $date, $segment = false)
    {
        Piwik::checkUserHasViewAccess($idSite);

        $table = Archive::createDataTableFromArchive(Errors::RECORD, $idSite, $period, $date, $segment, true);
        $table->filter(function (DataTable $table) {
            $site = $table->getMetadata('site');
            $timezone = $site ? $site->getTimezone() : 'UTC';
            $period = $table->getMetadata('period');
            $periodStart = $period ? strtotime($period->getDateStart()->setTimezone($timezone)->getDateStartUTC() . ' UTC') : 0;

            foreach ($table->getRows() as $row) {
                $message = $row->getMetadata('message');
                if ($message === false) {
                    // The row that sums up the errors beyond the most frequent ones.
                    $row->setColumn('label', Piwik::translate('General_Others'));
                    $row->deleteColumn('last_seen');
                    $row->deleteColumn('first_seen');
                    $row->deleteColumn('max_idlog');
                    continue;
                }

                $row->setColumn('label', $message);
                $row->setColumn('type', Piwik::translate('RebelErrorTracking_Type_' . $row->getMetadata('type')));
                $row->setColumn('source', $row->getMetadata('source'));
                $row->setColumn('browsers', $this->getMostFrequentBrowsers($row->getSubtable()));
                // The row opens its occurrences, not the browsers that are archived under it.
                $row->removeSubtable();
                $row->deleteMetadata('idsubdatatable_in_db');
                $row->setColumn('last_seen', Date::factory((int) $row->getColumn('last_seen'), $timezone)->getDatetime());
                $firstSeen = (int) $row->getColumn('first_seen');
                if ($firstSeen) {
                    $row->setColumn('first_seen', Date::factory($firstSeen, $timezone)->getDatetime());
                    $row->setColumn('is_new', (int) ($periodStart && $firstSeen >= $periodStart));
                }
                $row->setNonLoadedSubtableId((int) $row->getColumn('max_idlog'));
                $row->deleteColumn('max_idlog');
                foreach (['message', 'type', 'source'] as $name) {
                    $row->deleteMetadata($name);
                }
            }
        });

        return $table;
    }

    /**
     * The errors of a period by the page they happened on.
     *
     * @param int|string $idSite
     * @param string $period
     * @param string $date
     * @param string|false $segment
     * @return DataTable|DataTable\Map
     */
    public function getPages($idSite, $period, $date, $segment = false)
    {
        Piwik::checkUserHasViewAccess($idSite);

        $table = Archive::createDataTableFromArchive(Errors::PAGES, $idSite, $period, $date, $segment);
        $table->filter('ColumnCallbackAddMetadata', ['label', 'url', function ($label) {
            return preg_match('#^https?://#i', (string) $label) ? Common::unsanitizeInputValue($label) : false;
        }]);

        return $table;
    }

    /**
     * The errors of a period by the browser and version they happened in.
     *
     * @param int|string $idSite
     * @param string $period
     * @param string $date
     * @param string|false $segment
     * @return DataTable|DataTable\Map
     */
    public function getBrowsers($idSite, $period, $date, $segment = false)
    {
        Piwik::checkUserHasViewAccess($idSite);

        $table = Archive::createDataTableFromArchive(Errors::BROWSERS, $idSite, $period, $date, $segment);
        $table->filter('ColumnCallbackAddMetadata', ['label', 'logo', 'Piwik\\Plugins\\DevicesDetection\\getBrowserLogo']);
        $table->filter('ColumnCallbackReplace', ['label', 'Piwik\\Plugins\\DevicesDetection\\getBrowserNameWithVersion']);

        return $table;
    }

    /**
     * The latest times one error happened: when, on which page, in which browser, where in the code and the stack
     * trace. They are read from the stored errors, without a segment, and are gone when those are
     * deleted.
     *
     * @param int $idSite
     * @param string $period
     * @param string $date
     * @param int $idSubtable The ID of the row in `getErrors`.
     * @return DataTable
     */
    public function getOccurrences($idSite, $period, $date, $idSubtable)
    {
        $idSite = (int) $idSite;
        Piwik::checkUserHasViewAccess($idSite);

        [$from, $to] = $this->getTimeRange($idSite, $period, $date);
        $timezone = Site::getTimezoneFor($idSite);

        $table = new DataTable();
        foreach ((new LogError())->getOccurrences($idSite, (int) $idSubtable, $from, $to, self::MAX_OCCURRENCES) as $error) {
            $location = $error['source'];
            if ($error['line_number'] > 0) {
                $location .= ':' . $error['line_number'] . ':' . $error['column_number'];
            }

            $time = Date::factory($error['server_time'], $timezone);
            $day = $time->toString('Y-m-d');
            $table->addRow(new Row([Row::METADATA => [
                // The visit the error happened in, in the visit log.
                'url' => 'index.php?module=CoreHome&action=index&idSite=' . $idSite . '&period=day&date=' . $day
                    . '#?idSite=' . $idSite . '&period=day&date=' . $day
                    . '&category=General_Visitors&subcategory=Live_VisitorLog&segment=visitId%3D%3D' . (int) $error['idvisit'],
            ], Row::COLUMNS => [
                'label' => $time->getDatetime(),
                'idvisit' => (int) $error['idvisit'],
                'page_url' => $error['page_url'],
                'browser' => $this->getBrowserAndSystem($error),
                'location' => $location,
                'stack' => $error['stack'],
            ]]));
        }

        return $table;
    }

    /**
     * The browsers an error happened most in, as "Firefox 120.0 (12), Safari 17.1 (3), +2".
     *
     * @param DataTable|false $browsers
     */
    private function getMostFrequentBrowsers($browsers): string
    {
        if (!$browsers) {
            return '';
        }

        $counts = [];
        foreach ($browsers->getRowsWithoutSummaryRow() as $row) {
            $counts[\Piwik\Plugins\DevicesDetection\getBrowserNameWithVersion($row->getColumn('label'))] = (int) $row->getColumn('nb_errors');
        }
        ksort($counts);
        arsort($counts);

        $shown = [];
        foreach (array_slice($counts, 0, self::MAX_BROWSERS_SHOWN, true) as $name => $count) {
            $shown[] = $name . ' (' . $count . ')';
        }
        // The browsers beyond the ones that are archived for an error count as one.
        $more = count($counts) - count($shown) + ($browsers->getSummaryRow() ? 1 : 0);
        if ($more > 0) {
            $shown[] = '+' . $more;
        }

        return implode(', ', $shown);
    }

    /**
     * @param array<string, mixed> $error
     */
    private function getBrowserAndSystem(array $error): string
    {
        if (empty($error['config_browser_name'])) {
            return '';
        }

        return \Piwik\Plugins\DevicesDetection\getBrowserNameWithVersion($error['config_browser_name'] . ';' . $error['config_browser_version'])
            . ', ' . \Piwik\Plugins\DevicesDetection\getOsFullName($error['config_os'] . ';' . $error['config_os_version']);
    }

    /**
     * The first and the last second of the period in UTC, as the days are in the timezone of the
     * website.
     *
     * @return array{string, string}
     */
    private function getTimeRange(int $idSite, string $period, string $date): array
    {
        $timezone = Site::getTimezoneFor($idSite);
        $period = $period === 'range' || Period::isMultiplePeriod($date, $period)
            ? new Range($period, $date, $timezone)
            : Factory::build($period, $date, $timezone);

        return [
            $period->getDateStart()->setTimezone($timezone)->getDateStartUTC(),
            $period->getDateEnd()->setTimezone($timezone)->getDateEndUTC(),
        ];
    }
}
