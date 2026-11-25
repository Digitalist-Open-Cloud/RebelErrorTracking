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
use Piwik\Plugins\Live\API as LiveApi;
use Piwik\Plugins\PrivacyManager\Model\DataSubjects;
use Piwik\Plugins\RebelErrorTracking\API as ErrorsApi;
use Piwik\Plugins\RebelErrorTracking\Dao\LogError;
use Piwik\Plugins\RebelErrorTracking\MeasurableSettings;
use Piwik\Plugins\RebelErrorTracking\Tasks;
use Piwik\Plugins\RebelErrorTracking\Updates_5_1_0;
use Piwik\Plugins\VisitsSummary\API as VisitsSummaryApi;
use Piwik\Updater;
use Piwik\Updater\Migration\Factory as MigrationFactory;
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

        Fixture::loadAllTranslations();
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

    public function testTheErrorsOfASegmentOfVisitsAreReported()
    {
        $tracker = $this->startVisit(1);
        $tracker->setUserId('alice');
        $this->trackError($tracker, ['ret_m' => 'Seen by Alice']);
        $this->trackError($tracker, ['ret_m' => 'Seen by Alice', 'ret_l' => 2]);
        $this->trackError($this->startVisit(1), ['ret_m' => 'Seen by someone else']);

        $this->assertSame(
            ['Seen by Alice'],
            ErrorsApi::getInstance()->getErrors(1, 'day', self::DAY, 'userId==alice')->getColumn('label')
        );
        $this->assertEquals(2, ErrorsApi::getInstance()->getErrors(1, 'day', self::DAY, 'userId==alice')->getFirstRow()->getColumn('nb_errors'));
        $this->assertEquals(2, ErrorsApi::getInstance()->get(1, 'day', self::DAY, 'userId==alice')->getFirstRow()->getColumn('nb_errors'));
        $this->assertCount(2, ErrorsApi::getInstance()->getErrors(1, 'day', self::DAY)->getRows());
    }

    public function testErrorsAreReportedByTheBrowserOfTheVisit()
    {
        $firefox = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:120.0) Gecko/20100101 Firefox/120.0';
        $safari = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Safari/605.1.15';

        $tracker = $this->startVisit(1, self::DAY, $firefox);
        $this->trackError($tracker, ['ret_m' => 'First']);
        $this->trackError($tracker, ['ret_m' => 'Second']);
        $this->trackError($this->startVisit(1, self::DAY, $safari), ['ret_m' => 'First']);

        $browsers = ErrorsApi::getInstance()->getBrowsers(1, 'day', self::DAY);
        $this->assertSame(['Firefox 120.0', 'Safari 17.1'], $browsers->getColumn('label'));
        $this->assertEquals([2, 1], $browsers->getColumn('nb_errors'));
        $this->assertEquals([1, 1], $browsers->getColumn('nb_visits'));

        $errors = ErrorsApi::getInstance()->getErrors(1, 'day', self::DAY);
        $first = $errors->getRowFromLabel('First');
        $this->assertSame('Firefox 120.0 (1), Safari 17.1 (1)', $first->getColumn('browsers'));
        $this->assertSame('Firefox 120.0 (1)', $errors->getRowFromLabel('Second')->getColumn('browsers'));
        $occurrences = ErrorsApi::getInstance()->getOccurrences(1, 'day', self::DAY, $first->getIdSubDataTable());
        $this->assertSame(['Safari 17.1, Mac 10.15', 'Firefox 120.0, Windows 10'], $occurrences->getColumn('browser'));
    }

    public function testABrowserAndAPageCanBeFollowedOverTime()
    {
        $firefox = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:120.0) Gecko/20100101 Firefox/120.0';
        $this->trackError($this->startVisit(1, '2024-03-04', $firefox), ['ret_m' => 'Boom']);
        $tracker = $this->startVisit(1, self::DAY, $firefox);
        $this->trackError($tracker, ['ret_m' => 'Boom']);
        $this->trackError($tracker, ['ret_m' => 'Bang']);

        $errorsOfTheDay = function (string $report, string $label) {
            $evolution = \Piwik\Plugins\API\API::getInstance()->getRowEvolution(1, 'day', '2024-03-04,' . self::DAY, 'RebelErrorTracking', $report, $label);

            return $evolution['reportData']->getTable(self::DAY)->getFirstRow()->getColumn('nb_errors');
        };

        $this->assertEquals(2, $errorsOfTheDay('getBrowsers', 'Firefox 120.0'));
        $this->assertEquals(2, $errorsOfTheDay('getPages', 'http://example.org/shop'));
        $this->assertSame(
            'browserCode==FF;browserVersion==120.0',
            ErrorsApi::getInstance()->getBrowsers(1, 'day', self::DAY)->getFirstRow()->getMetadata('segment')
        );
    }

    public function testTheBrowsersOfAnErrorAreSummedOverSeveralDays()
    {
        $firefox = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:120.0) Gecko/20100101 Firefox/120.0';
        $safari = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Safari/605.1.15';

        $this->trackError($this->startVisit(1, '2024-03-04', $firefox), ['ret_m' => 'Boom']);
        $this->trackError($this->startVisit(1, self::DAY, $firefox), ['ret_m' => 'Boom']);
        $this->trackError($this->startVisit(1, self::DAY, $safari), ['ret_m' => 'Boom']);

        $week = ErrorsApi::getInstance()->getErrors(1, 'week', self::DAY);

        $this->assertSame(['Firefox 120.0 (2), Safari 17.1 (1)'], $week->getColumn('browsers'));
    }

    public function testOtherReportsCanBeNarrowedToVisitsWithAnError()
    {
        $tracker = $this->startVisit(1);
        $this->trackError($tracker, ['ret_m' => 'Checkout failed']);
        $this->trackError($tracker, ['ret_m' => 'Checkout failed again', 'ret_t' => 'promise']);
        $this->trackError($this->startVisit(1), ['ret_m' => 'Other error']);
        $this->startVisit(1);

        $visits = function (string $segment) {
            return VisitsSummaryApi::getInstance()->get(1, 'day', self::DAY, $segment)->getFirstRow()->getColumn('nb_visits');
        };

        $this->assertEquals(3, $visits(''));
        $this->assertEquals(1, $visits('errorMessage=@Checkout'));
        $this->assertEquals(1, $visits('errorType==promise'));
        $this->assertEquals(2, $visits('errorType==js'));
        $this->assertEquals(2, $visits('errorType!='), 'visits with an error');

        $this->trackError($this->startVisit(1), ['ret_m' => "Can't read <price> & more"]);
        $this->assertEquals(1, $visits("errorMessage=@Can't read <price> &"), 'a message with characters that are stored encoded');
        $this->assertEquals(1, $visits('errorType=='), 'visits without an error');
    }

    public function testTheVisitLogShowsTheErrorsOfAVisit()
    {
        $tracker = $this->startVisit(1);
        $this->trackError($tracker, ['ret_m' => 'Boom', 'ret_s' => 'http://example.org/app.js', 'ret_l' => 7, 'ret_c' => 3]);
        $this->startVisit(1);

        $visits = LiveApi::getInstance()->getLastVisitsDetails(1, 'day', self::DAY);
        $visits->applyQueuedFilters();
        $errors = [];
        foreach ($visits->getRows() as $visit) {
            foreach ($visit->getColumn('actionDetails') as $action) {
                if ($action['type'] === 'error') {
                    $errors[] = [$action['title'], $action['errorMessage'], $action['errorLocation']];
                }
            }
        }

        $this->assertSame([['JavaScript', 'Boom', 'http://example.org/app.js:7:3']], $errors);
    }

    public function testAnErrorIsNewInThePeriodItWasFirstSeenIn()
    {
        $this->trackError($this->startVisit(1, '2024-03-04'), ['ret_m' => 'Boom']);
        $this->trackError($this->startVisit(1), ['ret_m' => 'Boom']);
        $this->trackError($this->startVisit(1), ['ret_m' => 'Bang']);

        $api = ErrorsApi::getInstance();
        $second = $api->getErrors(1, 'day', self::DAY);
        $this->assertSame('2024-03-04 10:01:00', $second->getRowFromLabel('Boom')->getColumn('first_seen'));
        $this->assertSame(0, $second->getRowFromLabel('Boom')->getColumn('is_new'));
        $this->assertSame(1, $second->getRowFromLabel('Bang')->getColumn('is_new'));
        $this->assertSame(1, $api->getErrors(1, 'day', '2024-03-04')->getRowFromLabel('Boom')->getColumn('is_new'));
        $this->assertSame(1, $api->getErrors(1, 'week', self::DAY)->getRowFromLabel('Boom')->getColumn('is_new'));
        $this->assertSame('2024-03-04 10:01:00', $api->getErrors(1, 'week', self::DAY)->getRowFromLabel('Boom')->getColumn('first_seen'));

        $this->assertEquals(1, $api->get(1, 'day', '2024-03-04')->getFirstRow()->getColumn('nb_new_errors'));
        $this->assertEquals(1, $api->get(1, 'day', self::DAY)->getFirstRow()->getColumn('nb_new_errors'));
        $this->assertEquals(2, $api->get(1, 'week', self::DAY)->getFirstRow()->getColumn('nb_new_errors'));
    }

    public function testTheShareOfVisitsWithAnErrorIsReported()
    {
        $this->trackError($this->startVisit(1), ['ret_m' => 'Boom']);
        $this->startVisit(1);
        $this->startVisit(1);
        $this->startVisit(1);

        $this->assertSame('25%', ErrorsApi::getInstance()->get(1, 'day', self::DAY)->getFirstRow()->getColumn('error_rate'));
        $this->assertSame('25%', ErrorsApi::getInstance()->get(1, 'day', self::DAY, false, 'error_rate')->getFirstRow()->getColumn('error_rate'));
    }

    public function testErrorsAreReportedByThePageTheyHappenedOn()
    {
        $tracker = $this->startVisit(1);
        $this->trackError($tracker, ['ret_m' => 'Boom']);
        $this->trackError($tracker, ['ret_m' => 'Bang']);
        $tracker->setUrl('http://example.org/cart#items');
        $this->trackError($tracker, ['ret_m' => 'Boom']);

        $pages = ErrorsApi::getInstance()->getPages(1, 'day', self::DAY);

        $this->assertSame(['http://example.org/shop', 'http://example.org/cart'], $pages->getColumn('label'));
        $this->assertEquals([2, 1], $pages->getColumn('nb_errors'));
        $this->assertSame('http://example.org/shop', $pages->getFirstRow()->getMetadata('url'));
    }

    public function testAnOccurrenceLinksToItsVisitInTheVisitLog()
    {
        $this->trackError($this->startVisit(1), ['ret_m' => 'Boom']);
        $idVisit = (int) Db::fetchOne('SELECT idvisit FROM ' . Common::prefixTable(LogError::TABLE));

        $api = ErrorsApi::getInstance();
        $occurrence = $api->getOccurrences(1, 'day', self::DAY, $api->getErrors(1, 'day', self::DAY)->getFirstRow()->getIdSubDataTable())->getFirstRow();

        // The table of Matomo opens a row by this ID when a row has one.
        $this->assertFalse($api->getErrors(1, 'day', self::DAY)->getFirstRow()->getMetadata('idsubdatatable_in_db'));
        $this->assertSame($idVisit, $occurrence->getColumn('idvisit'));
        $this->assertStringStartsWith('http', $occurrence->getMetadata('url'));
        $this->assertStringContainsString('date=' . self::DAY, $occurrence->getMetadata('url'));
        $this->assertStringEndsWith('subcategory=Live_VisitorLog&segment=visitId%3D%3D' . $idVisit, $occurrence->getMetadata('url'));
    }

    public function testAnErrorThatNoLongerHappensIsNewWhenItComesBack()
    {
        $this->trackError($this->startVisit(1, gmdate('Y-m-d', time() - 200 * 86400)), ['ret_m' => 'Old']);
        $this->trackError($this->startVisit(1, gmdate('Y-m-d', time() - 5 * 86400)), ['ret_m' => 'Recent']);

        (new Tasks())->deleteOldErrors();

        $this->assertEquals(1, Db::fetchOne('SELECT COUNT(*) FROM ' . Common::prefixTable(LogError::FIRST_SEEN_TABLE)));
    }

    public function testTheUpdateFillsInWhenTheStoredErrorsWereFirstSeen()
    {
        $this->trackError($this->startVisit(1, '2024-03-04'), ['ret_m' => 'Boom']);
        $this->trackError($this->startVisit(1), ['ret_m' => 'Boom']);
        Db::query('DROP TABLE ' . Common::prefixTable(LogError::FIRST_SEEN_TABLE));
        Db::query('ALTER TABLE ' . Common::prefixTable(LogError::TABLE) . ' DROP INDEX site_fingerprint');

        require_once PIWIK_INCLUDE_PATH . '/plugins/RebelErrorTracking/Updates/5.1.0.php';
        $updater = new Updater();
        (new Updates_5_1_0(StaticContainer::get(MigrationFactory::class)))->doUpdate($updater);
        // It can run again on tables that are already updated.
        (new Updates_5_1_0(StaticContainer::get(MigrationFactory::class)))->doUpdate($updater);

        $this->assertSame(
            ['2024-03-04 10:01:00'],
            array_column(Db::fetchAll('SELECT first_seen FROM ' . Common::prefixTable(LogError::FIRST_SEEN_TABLE)), 'first_seen')
        );
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

    public function testBrowserWarningsAreOnlyStoredForAWebsiteThatAsksForThem()
    {
        $settings = new MeasurableSettings(2);
        $settings->browserWarnings->setValue(true);
        $settings->save();

        $warning = ['ret_t' => 'browser', 'ret_m' => 'deprecation: Unload event listeners are deprecated'];
        $this->trackError($this->startVisit(1), $warning);
        $this->trackError($this->startVisit(2), $warning);

        $this->assertEquals([2], array_column($this->getLog(), 'idsite'));
    }

    public function testAContentSecurityPolicyViolationIsStored()
    {
        $this->trackError($this->startVisit(1), ['ret_t' => 'csp', 'ret_m' => 'Blocked by img-src', 'ret_s' => 'https://evil.example/x.png']);

        $this->assertSame(['csp'], array_column($this->getLog(), 'type'));
        $this->assertSame(['Content Security Policy'], ErrorsApi::getInstance()->getErrors(1, 'day', self::DAY)->getColumn('type'));
    }

    public function testErrorsAWebsiteIgnoresAreNotStored()
    {
        $settings = new MeasurableSettings(1);
        $settings->ignored->setValue("chat-widget\n\n  can't find VARIABLE \n");
        $settings->save();

        $tracker = $this->startVisit(1);
        $this->trackError($tracker, ['ret_m' => 'Boom', 'ret_s' => 'https://cdn.example/Chat-Widget.js']);
        $this->trackError($tracker, ['ret_m' => "ReferenceError: Can't find variable: x"]);
        $this->trackError($tracker, ['ret_m' => 'Kept', 'ret_s' => 'https://example.org/app.js']);
        $this->trackError($this->startVisit(2), ['ret_m' => 'Boom', 'ret_s' => 'https://cdn.example/chat-widget.js']);

        $this->assertSame(['Kept', 'Boom'], array_column($this->getLog(), 'message'));
        $this->assertEquals([1, 2], array_column($this->getLog(), 'idsite'));
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
    private function startVisit(int $idSite, string $day = self::DAY, string $userAgent = ''): \MatomoTracker
    {
        $tracker = Fixture::getTracker($idSite, $day . ' 10:00:00', true, true);
        $tracker->setTokenAuth(Fixture::getTokenAuth());
        $tracker->setNewVisitorId();
        // Matomo takes requests from one address for one visit.
        $tracker->setIp('10.0.0.' . (1 + $this->visits++));
        if ($userAgent !== '') {
            $tracker->setUserAgent($userAgent);
        }
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
