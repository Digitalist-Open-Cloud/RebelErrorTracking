<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelErrorTracking\tests\Integration;

use Piwik\Common;
use Piwik\Container\StaticContainer;
use Piwik\Db;
use Piwik\LogDeleter;
use Piwik\Plugins\PrivacyManager\Model\DataSubjects;
use Piwik\Plugins\RebelErrorTracking\Dao\LogError;
use Piwik\Plugins\RebelErrorTracking\MeasurableSettings;
use Piwik\Plugins\RebelErrorTracking\Tasks;
use Piwik\Plugins\VisitsSummary\API as VisitsSummaryApi;
use Piwik\Tests\Framework\Fixture;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

/**
 * @group RebelErrorTracking
 * @group Plugins
 */
class TrackingTest extends IntegrationTestCase
{
    private const DAY = '2024-03-05';

    /**
     * @var int
     */
    private $visits = 0;

    public function setUp(): void
    {
        parent::setUp();

        Fixture::createSuperUser();
        Fixture::createWebsite('2024-01-01 00:00:00');
        Fixture::createWebsite('2024-01-01 00:00:00');
        $this->visits = 0;
    }

    public function testAnErrorIsStoredWithTheVisitItHappenedIn()
    {
        $tracker = $this->startVisit(1);
        $this->trackError($tracker, ['ret_m' => 'Uncaught Error: Boom', 'ret_s' => 'http://example.org/app.js', 'ret_l' => 7, 'ret_c' => 3]);

        $errors = $this->getLog();
        $this->assertCount(1, $errors);
        $this->assertSame('js', $errors[0]['type']);
        $this->assertSame('Uncaught Error: Boom', $errors[0]['message']);
        $this->assertSame('http://example.org/app.js', $errors[0]['source']);
        $this->assertEquals(7, $errors[0]['line_number']);
        $this->assertSame('http://example.org/shop', $errors[0]['page_url']);
        $this->assertSame(self::DAY . ' 10:01:00', $errors[0]['server_time']);
        $this->assertEquals(1, $errors[0]['idsite']);
        $this->assertEquals(
            Db::fetchOne('SELECT idvisit FROM ' . Common::prefixTable('log_visit')),
            $errors[0]['idvisit']
        );
    }

    public function testAnErrorIsNotCountedAsAPageView()
    {
        $tracker = $this->startVisit(1);
        $this->trackError($tracker, ['ret_m' => 'Boom']);

        $summary = VisitsSummaryApi::getInstance()->get(1, 'day', self::DAY)->getFirstRow();
        $this->assertEquals(1, $summary->getColumn('nb_visits'));
        $this->assertEquals(1, $summary->getColumn('nb_actions'));
    }

    public function testARequestWithoutAUsableErrorStoresNothing()
    {
        $tracker = $this->startVisit(1);
        $this->trackError($tracker, ['ret_t' => 'other', 'ret_m' => 'Boom']);

        $this->assertSame([], $this->getLog());
    }

    public function testNothingIsStoredForAWebsiteWithErrorTrackingTurnedOff()
    {
        $settings = new MeasurableSettings(2);
        $settings->enabled->setValue(false);
        $settings->save();

        $this->trackError($this->startVisit(1), ['ret_m' => 'Kept']);
        $this->trackError($this->startVisit(2), ['ret_m' => 'Dropped']);

        $this->assertSame(['Kept'], array_column($this->getLog(), 'message'));
    }

    public function testAVisitCanNotStoreMoreThanAHundredErrors()
    {
        $tracker = $this->startVisit(1);
        for ($i = 0; $i < 103; $i++) {
            $this->trackError($tracker, ['ret_m' => 'Boom ' . $i]);
        }

        $this->assertCount(100, $this->getLog());
    }

    public function testErrorsAreDeletedWithTheDataOfAVisitor()
    {
        $this->trackError($this->startVisit(1), ['ret_m' => 'First']);
        $this->trackError($this->startVisit(1), ['ret_m' => 'Second']);
        $idVisit = (int) Db::fetchOne('SELECT idvisit FROM ' . Common::prefixTable(LogError::TABLE) . " WHERE message = 'First'");

        StaticContainer::get(DataSubjects::class)->deleteDataSubjects([['idsite' => 1, 'idvisit' => $idVisit]]);

        $this->assertSame(['Second'], array_column($this->getLog(), 'message'));
    }

    public function testErrorsAreDeletedWithOldVisits()
    {
        $this->trackError($this->startVisit(1, '2024-03-02'), ['ret_m' => 'Old']);
        $this->trackError($this->startVisit(1), ['ret_m' => 'New']);

        StaticContainer::get(LogDeleter::class)->deleteVisitsFor('2024-03-01 00:00:00', '2024-03-03 00:00:00', 1);

        $this->assertSame(['New'], array_column($this->getLog(), 'message'));
    }

    public function testTheTaskDeletesErrorsOlderThanTheDaysToKeepThem()
    {
        $this->trackError($this->startVisit(1, gmdate('Y-m-d', time() - 200 * 86400)), ['ret_m' => 'Old']);
        $this->trackError($this->startVisit(1, gmdate('Y-m-d', time() - 5 * 86400)), ['ret_m' => 'Recent']);

        (new Tasks())->deleteOldErrors();

        $this->assertSame(['Recent'], array_column($this->getLog(), 'message'));
    }

    /**
     * A visit with one page view.
     */
    private function startVisit(int $idSite, string $day = self::DAY): \MatomoTracker
    {
        $tracker = Fixture::getTracker($idSite, $day . ' 10:00:00', true, true);
        $tracker->setTokenAuth(Fixture::getTokenAuth());
        $tracker->setNewVisitorId();
        // Matomo takes requests from one address for one visit.
        $tracker->setIp('10.0.0.' . (1 + $this->visits++));
        $tracker->setUrl('http://example.org/shop?step=2');
        Fixture::checkResponse($tracker->doTrackPageView('Shop'));
        $tracker->setForceVisitDateTime($day . ' 10:01:00');

        return $tracker;
    }

    private function trackError(\MatomoTracker $tracker, array $params): void
    {
        foreach ($params + ['ca' => 1, 'ret_t' => 'js'] as $name => $value) {
            $tracker->setCustomTrackingParameter($name, $value);
        }
        Fixture::checkResponse($tracker->doTrackPageView('Shop'));
    }

    private function getLog(): array
    {
        return Db::fetchAll('SELECT * FROM ' . Common::prefixTable(LogError::TABLE) . ' ORDER BY idlog');
    }

    protected static function configureFixture($fixture)
    {
        parent::configureFixture($fixture);
        $fixture->createSuperUser = true;
    }
}
