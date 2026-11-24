<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelErrorTracking\Columns;

use Piwik\Columns\Dimension;
use Piwik\Plugins\RebelErrorTracking\Categories\ErrorsCategory;
use Piwik\Plugins\RebelErrorTracking\Dao\LogError;

class ErrorType extends Dimension
{
    protected $type = self::TYPE_TEXT;
    protected $dbTableName = LogError::TABLE;
    protected $columnName = 'type';
    protected $segmentName = 'errorType';
    protected $nameSingular = 'RebelErrorTracking_DimensionType';
    protected $namePlural = 'RebelErrorTracking_DimensionTypes';
    protected $category = ErrorsCategory::ID;
    protected $acceptValues = 'js, promise, resource, csp, browser';
}
