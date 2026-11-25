<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelErrorTracking\Columns;

use Piwik\Columns\Dimension;

/**
 * Names the rows of the report by page. It is not a segment.
 */
class ErrorPage extends Dimension
{
    protected $type = self::TYPE_URL;
    protected $nameSingular = 'RebelErrorTracking_ColumnPage';
}
