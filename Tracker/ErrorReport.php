<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelErrorTracking\Tracker;

use Piwik\Common;

/**
 * An error as a browser sent it. Nothing in it can be trusted.
 */
class ErrorReport
{
    public const PARAMETER = 'ret_t';

    public const TYPE_JS = 'js';
    public const TYPE_PROMISE = 'promise';
    public const TYPE_RESOURCE = 'resource';
    public const TYPE_CSP = 'csp';
    // What a browser says about the page: a feature that is deprecated, or something it stepped in to stop.
    public const TYPE_BROWSER = 'browser';
    public const TYPES = [self::TYPE_JS, self::TYPE_PROMISE, self::TYPE_RESOURCE, self::TYPE_CSP, self::TYPE_BROWSER];

    private const MAX_MESSAGE = 500;
    private const MAX_ADDRESS = 500;
    private const MAX_STACK = 2000;
    private const MAX_NUMBER = 10000000;

    /**
     * The columns to store, or null when the request does not hold a usable error. The texts are
     * encoded for HTML, as Matomo stores what the tracker receives.
     *
     * @param array<string, mixed> $params
     * @return array<string, int|string>|null
     */
    public static function fromRequestParams(array $params): ?array
    {
        $type = $params[self::PARAMETER] ?? '';
        $message = self::text($params['ret_m'] ?? '', self::MAX_MESSAGE);
        if (!in_array($type, self::TYPES, true) || $message === '') {
            return null;
        }

        $source = self::address($params['ret_s'] ?? '');

        return [
            'type' => $type,
            'fingerprint' => self::fingerprint($type, $message, $source),
            'message' => Common::sanitizeInputValue($message),
            'source' => Common::sanitizeInputValue($source),
            'line_number' => self::number($params['ret_l'] ?? 0),
            'column_number' => self::number($params['ret_c'] ?? 0),
            'stack' => self::encodeLines(self::text($params['ret_k'] ?? '', self::MAX_STACK)),
            'page_url' => Common::sanitizeInputValue(self::address($params['url'] ?? '')),
        ];
    }

    /**
     * The same for errors that differ only in numbers and IDs, such as the hash in a file name.
     */
    public static function fingerprint(string $type, string $message, string $source): string
    {
        $pattern = '/[0-9a-f]{8,}|[0-9]+/i';

        return md5($type . '|' . preg_replace($pattern, '0', $message) . '|' . preg_replace($pattern, '0', $source));
    }

    /**
     * Encoded as the other texts, with the line breaks kept: a stack trace is one frame on each line.
     */
    private static function encodeLines(string $text): string
    {
        return implode("\n", array_map([Common::class, 'sanitizeInputValue'], preg_split('/\R/', $text)));
    }

    /**
     * @param mixed $value
     */
    private static function text($value, int $max): string
    {
        if (!is_string($value)) {
            return '';
        }

        return trim(mb_substr(mb_scrub($value, 'UTF-8'), 0, $max, 'UTF-8'));
    }

    /**
     * @param mixed $value
     */
    private static function address($value): string
    {
        // What follows ? and # can hold personal data and is not needed to find the error.
        return preg_split('/[?#]/', self::text($value, self::MAX_ADDRESS))[0];
    }

    /**
     * @param mixed $value
     */
    private static function number($value): int
    {
        return is_numeric($value) ? max(0, min(self::MAX_NUMBER, (int) $value)) : 0;
    }
}
