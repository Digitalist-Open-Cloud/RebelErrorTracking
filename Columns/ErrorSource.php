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

class ErrorSource extends Dimension
{
    protected $type = self::TYPE_TEXT;
    protected $dbTableName = LogError::TABLE;
    protected $columnName = 'source';
    protected $segmentName = 'errorSource';
    protected $nameSingular = 'RebelErrorTracking_DimensionSource';
    protected $namePlural = 'RebelErrorTracking_DimensionSources';
    protected $category = ErrorsCategory::ID;
}
