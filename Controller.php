<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelErrorTracking;

use Piwik\Piwik;
use Piwik\Plugin\ControllerAdmin;
use Piwik\Plugins\RebelErrorTracking\Actions\ActionError;

class Controller extends ControllerAdmin
{
    // As the tracker of the plugin limits it.
    private const MAX_ERRORS_PER_PAGE_LOAD = 10;

    /**
     * The page that tells what is tracked and how to read the reports, with the limits as they are set.
     */
    public function documentation(): string
    {
        Piwik::checkUserHasSomeViewAccess();

        return $this->renderTemplate('documentation', [
            'maxPerPageLoad' => self::MAX_ERRORS_PER_PAGE_LOAD,
            'maxPerVisit' => ActionError::MAX_ERRORS_PER_VISIT,
            'retentionDays' => (new SystemSettings())->getRetentionDays(),
        ]);
    }
}
