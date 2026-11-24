<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelErrorTracking\tests\Integration;

use Piwik\NoAccessException;
use Piwik\Plugins\RebelErrorTracking\API;
use Piwik\Plugins\RebelErrorTracking\Dao\LogError;
use Piwik\Plugins\RebelErrorTracking\Tracker\ErrorReport;
use Piwik\Tests\Framework\Fixture;
use Piwik\Tests\Framework\Mock\FakeAccess;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

/**
 * @group RebelErrorTracking
 * @group Plugins
 */
class ApiTest extends IntegrationTestCase
{
    private const DAY = '2024-03-05';

    public function setUp(): void
    {
        parent::setUp();

        Fixture::loadAllTranslations();
        Fixture::createWebsite('2024-01-01 00:00:00');
        Fixture::createWebsite('2024-01-01 00:00:00', 0, false, false, 1, null, null, 'Europe/Stockholm');
    }

    public function testTheSameErrorIsOneRowWithItsCounts()
    {
        $this->addError(1, 10, 'Order 12 failed', '10:00:00');
        $this->addError(1, 10, 'Order 13 failed', '10:05:00');
        $this->addError(1, 11, 'Order 14 failed', '11:00:00');
        $this->addError(1, 11, 'Other error', '12:00:00');

        $rows = API::getInstance()->getErrors(1, 'day', self::DAY)->getRows();

        $this->assertCount(2, $rows);
        $first = reset($rows);
        $this->assertSame(3, $first->getColumn('nb_errors'));
        $this->assertSame(2, $first->getColumn('nb_visits'));
        $this->assertSame(self::DAY . ' 11:00:00', $first->getColumn('last_seen'));
        $this->assertSame('JavaScript', $first->getColumn('type'));
        $this->assertSame('http://example.org/app.js', $first->getColumn('source'));
        $this->assertSame('Other error', end($rows)->getColumn('label'));
    }

    public function testOnlyErrorsOfTheWebsiteAndThePeriodAreReported()
    {
        $this->addError(1, 10, 'Today', '10:00:00');
        $this->addError(1, 10, 'Yesterday', '10:00:00', '2024-03-04');
        $this->addError(2, 10, 'Other website', '10:00:00');

        $this->assertSame(['Today'], $this->getMessages(1, 'day', self::DAY));
        $this->assertEqualsCanonicalizing(['Today', 'Yesterday'], $this->getMessages(1, 'week', self::DAY));
        $this->assertEqualsCanonicalizing(['Today', 'Yesterday'], $this->getMessages(1, 'day', '2024-03-04,2024-03-05'));
        $this->assertEqualsCanonicalizing(['Today', 'Yesterday'], $this->getMessages(1, 'range', '2024-03-04,2024-03-05'));
    }

    public function testADayIsADayInTheTimezoneOfTheWebsite()
    {
        // 23:30 UTC is the next day in Stockholm.
        $this->addError(2, 10, 'Late', '23:30:00', '2024-03-04');

        $this->assertSame([], $this->getMessages(2, 'day', '2024-03-04'));
        $this->assertSame(['Late'], $this->getMessages(2, 'day', self::DAY));
        $this->assertSame(
            self::DAY . ' 00:30:00',
            API::getInstance()->getErrors(2, 'day', self::DAY)->getFirstRow()->getColumn('last_seen')
        );
    }

    public function testTheOccurrencesOfAnErrorAreListedNewestFirst()
    {
        $this->addError(1, 10, 'Order 12 failed', '10:00:00');
        $this->addError(1, 11, 'Order 13 failed', '11:00:00');
        $this->addError(1, 11, 'Other error', '12:00:00');
        $this->addError(2, 11, 'Order 13 failed', '12:00:00');

        $idSubtable = API::getInstance()->getErrors(1, 'day', self::DAY)->getFirstRow()->getIdSubDataTable();
        $rows = API::getInstance()->getOccurrences(1, 'day', self::DAY, $idSubtable)->getRows();

        $this->assertSame([self::DAY . ' 11:00:00', self::DAY . ' 10:00:00'], array_map(function ($row) {
            return $row->getColumn('label');
        }, array_values($rows)));
        $first = reset($rows);
        $this->assertSame('http://example.org/app.js:7:3', $first->getColumn('location'));
        $this->assertSame('http://example.org/shop', $first->getColumn('page_url'));
        $this->assertSame('Error at run', $first->getColumn('stack'));
    }

    public function testTheOccurrencesOfAnErrorOfAnotherWebsiteAreNotGiven()
    {
        $this->addError(1, 10, 'Boom', '10:00:00');
        $this->addError(2, 10, 'Boom', '10:00:00');
        $idOfSiteOne = API::getInstance()->getErrors(1, 'day', self::DAY)->getFirstRow()->getIdSubDataTable();

        $this->assertSame(0, API::getInstance()->getOccurrences(2, 'day', self::DAY, $idOfSiteOne)->getRowsCount());
    }

    /**
     * @dataProvider getReports
     */
    public function testAUserWithoutAccessToTheWebsiteGetsNoErrors(string $method, array $arguments)
    {
        $this->addError(2, 10, 'Boom', '10:00:00');
        FakeAccess::clearAccess(false, [], [1]);

        $this->expectException(NoAccessException::class);
        API::getInstance()->$method(...$arguments);
    }

    public function testAUserWithViewAccessGetsTheErrors()
    {
        $this->addError(1, 10, 'Boom', '10:00:00');
        FakeAccess::clearAccess(false, [], [1]);

        $this->assertSame(['Boom'], $this->getMessages(1, 'day', self::DAY));
    }

    public function getReports(): array
    {
        return [
            ['getErrors', [2, 'day', self::DAY]],
            ['getOccurrences', [2, 'day', self::DAY, 1]],
        ];
    }

    private function addError(int $idSite, int $idVisit, string $message, string $time, string $day = self::DAY): void
    {
        $source = 'http://example.org/app.js';

        (new LogError())->record([
            'idsite' => $idSite,
            'idvisit' => $idVisit,
            'idvisitor' => hex2bin('0123456789abcdef'),
            'server_time' => $day . ' ' . $time,
            'type' => 'js',
            'fingerprint' => ErrorReport::fingerprint('js', $message, $source),
            'message' => $message,
            'source' => $source,
            'line_number' => 7,
            'column_number' => 3,
            'stack' => 'Error at run',
            'page_url' => 'http://example.org/shop',
        ]);
    }

    private function getMessages(int $idSite, string $period, string $date): array
    {
        return API::getInstance()->getErrors($idSite, $period, $date)->getColumn('label');
    }

    public function provideContainerConfig()
    {
        return ['Piwik\Access' => new FakeAccess()];
    }
}
