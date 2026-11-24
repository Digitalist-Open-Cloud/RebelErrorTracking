<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelErrorTracking\tests\Unit;

use PHPUnit\Framework\TestCase;
use Piwik\Plugins\RebelErrorTracking\Tracker\ErrorReport;

/**
 * @group RebelErrorTracking
 * @group Plugins
 */
class ErrorReportTest extends TestCase
{
    public function testAnErrorIsReadFromTheRequest()
    {
        $error = ErrorReport::fromRequestParams([
            'ret_t' => 'js',
            'ret_m' => ' Uncaught TypeError: x is not a function ',
            'ret_s' => 'https://example.org/app.js?v=3#top',
            'ret_l' => '12',
            'ret_c' => '34',
            'ret_k' => "TypeError: x is not a function\n    at run (https://example.org/app.js:12:34)",
            'url' => 'https://example.org/checkout?email=someone@example.org',
        ]);

        $this->assertSame('js', $error['type']);
        $this->assertSame('Uncaught TypeError: x is not a function', $error['message']);
        $this->assertSame('https://example.org/app.js', $error['source']);
        $this->assertSame(12, $error['line_number']);
        $this->assertSame(34, $error['column_number']);
        $this->assertStringContainsString("\n    at run", $error['stack']);
        $this->assertSame('https://example.org/checkout', $error['page_url']);
    }

    /**
     * @dataProvider getRequestsWithoutAnError
     */
    public function testARequestWithoutAUsableErrorIsRefused(array $params)
    {
        $this->assertNull(ErrorReport::fromRequestParams($params));
    }

    public function getRequestsWithoutAnError(): array
    {
        return [
            'no error' => [['url' => 'https://example.org/']],
            'unknown type' => [['ret_t' => 'other', 'ret_m' => 'Boom']],
            'no message' => [['ret_t' => 'js', 'ret_m' => '  ']],
            'message is not a text' => [['ret_t' => 'js', 'ret_m' => ['Boom']]],
        ];
    }

    public function testMarkupIsStoredEncoded()
    {
        $error = ErrorReport::fromRequestParams([
            'ret_t' => 'js',
            'ret_m' => '<img src=x onerror=alert(1)>',
            'ret_s' => 'https://example.org/"><b>.js',
            'ret_k' => '<script>alert(1)</script>',
            'url' => 'https://example.org/<i>',
        ]);

        foreach (['message', 'source', 'stack', 'page_url'] as $column) {
            $this->assertStringNotContainsString('<', $error[$column], $column);
            $this->assertStringNotContainsString('"', $error[$column], $column);
        }
    }

    public function testTextsAreCutAndNumbersAreKeptInRange()
    {
        $error = ErrorReport::fromRequestParams([
            'ret_t' => 'promise',
            'ret_m' => str_repeat('å', 5000),
            'ret_s' => str_repeat('s', 5000),
            'ret_l' => '-5',
            'ret_c' => '99999999999999999999',
            'ret_k' => str_repeat('k', 50000) . "\xff",
        ]);

        $this->assertSame(500, mb_strlen($error['message']));
        $this->assertSame(500, strlen($error['source']));
        $this->assertSame(2000, strlen($error['stack']));
        $this->assertSame(0, $error['line_number']);
        $this->assertSame(10000000, $error['column_number']);
    }

    public function testErrorsThatDifferOnlyInNumbersAndIdsAreTheSameError()
    {
        $one = ErrorReport::fingerprint('js', 'Order 1234 failed', 'https://example.org/app.3f2a1bc9d0.js');
        $other = ErrorReport::fingerprint('js', 'Order 98 failed', 'https://example.org/app.aa11bb22cc.js');

        $this->assertSame($one, $other);
        $this->assertNotSame($one, ErrorReport::fingerprint('js', 'Payment 1234 failed', 'https://example.org/app.3f2a1bc9d0.js'));
        $this->assertNotSame($one, ErrorReport::fingerprint('promise', 'Order 1234 failed', 'https://example.org/app.3f2a1bc9d0.js'));
    }
}
