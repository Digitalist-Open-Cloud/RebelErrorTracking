<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelErrorTracking\Categories;

use Piwik\Category\Category;

class ErrorsCategory extends Category
{
    public const ID = 'RebelErrorTracking_Errors';

    protected $id = self::ID;
    protected $order = 67;
    protected $icon = 'rebelErrorTrackingIcon';
}
