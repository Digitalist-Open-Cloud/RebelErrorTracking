<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelErrorTracking;

use Piwik\DataTable;
use Piwik\DataTable\Row;
use Piwik\Date;
use Piwik\Period;
use Piwik\Period\Factory;
use Piwik\Period\Range;
use Piwik\Piwik;
use Piwik\Plugins\RebelErrorTracking\Dao\LogError;
use Piwik\Site;

/**
 * The errors that happened in the browsers of the visitors of a website.
 *
 * @method static \Piwik\Plugins\RebelErrorTracking\API getInstance()
 */
class API extends \Piwik\Plugin\API
{
    private const MAX_OCCURRENCES = 20;

    /**
     * The errors of a website in a period, the same error counted as one row. A period of several
     * dates, such as `last7`, is reported as one.
     *
     * @param int $idSite
     * @param string $period
     * @param string $date
     * @return DataTable
     */
    public function getErrors($idSite, $period, $date)
    {
        $idSite = (int) $idSite;
        Piwik::checkUserHasViewAccess($idSite);

        [$from, $to] = $this->getTimeRange($idSite, $period, $date);
        $timezone = Site::getTimezoneFor($idSite);

        $table = new DataTable();
        foreach ((new LogError())->getGroups($idSite, $from, $to) as $group) {
            $row = new Row([Row::COLUMNS => [
                'label' => $group['message'],
                'type' => Piwik::translate('RebelErrorTracking_Type_' . $group['type']),
                'source' => $group['source'],
                'nb_errors' => (int) $group['nb_errors'],
                'nb_visits' => (int) $group['nb_visits'],
                'last_seen' => Date::factory($group['last_seen'], $timezone)->getDatetime(),
            ]]);
            $row->setNonLoadedSubtableId((int) $group['idlog']);
            $table->addRow($row);
        }

        return $table;
    }

    /**
     * The latest times one error happened: when, on which page, where in the code and the stack
     * trace.
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

            $table->addRow(new Row([Row::COLUMNS => [
                'label' => Date::factory($error['server_time'], $timezone)->getDatetime(),
                'page_url' => $error['page_url'],
                'location' => $location,
                'stack' => $error['stack'],
            ]]));
        }

        return $table;
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
