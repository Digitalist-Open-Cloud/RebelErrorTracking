<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelErrorTracking\tests\Integration;

use Piwik\NoAccessException;
use Piwik\Plugins\RebelErrorTracking\Controller;
use Piwik\Tests\Framework\Fixture;
use Piwik\Tests\Framework\Mock\FakeAccess;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

/**
 * @group RebelErrorTracking
 * @group Plugins
 */
class ControllerTest extends IntegrationTestCase
{
    public function setUp(): void
    {
        parent::setUp();

        Fixture::loadAllTranslations();
        Fixture::createWebsite('2024-01-01 00:00:00');
    }

    public function testAUserWithViewAccessReadsTheDocumentationWithTheLimitsAsTheyAreSet()
    {
        FakeAccess::clearAccess(false, [], [1]);

        $page = (new Controller())->documentation();

        $this->assertStringContainsString('What counts as the same error', $page);
        $this->assertStringContainsString('at most 10 errors for each page load', $page);
        $this->assertStringContainsString('A visit stores at most 100.', $page);
        $this->assertStringContainsString('Errors are kept for 90 days', $page);
        $this->assertStringContainsString('window.rebelErrorQueue = [];', $page);
        // No text of the page is left untranslated.
        $this->assertSame(0, preg_match('/RebelErrorTracking_Doc[A-Z]/', $page));
    }

    public function testAUserWithoutAccessToAnyWebsiteDoesNotGetTheDocumentation()
    {
        FakeAccess::clearAccess(false);

        $this->expectException(NoAccessException::class);
        (new Controller())->documentation();
    }

    public function provideContainerConfig()
    {
        return ['Piwik\Access' => new FakeAccess()];
    }
}
