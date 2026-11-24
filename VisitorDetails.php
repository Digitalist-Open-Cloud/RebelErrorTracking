<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelErrorTracking;

use Piwik\Piwik;
use Piwik\Plugins\Live\VisitorDetailsAbstract;
use Piwik\Plugins\RebelErrorTracking\Dao\LogError;
use Piwik\View;

/**
 * Shows the errors of a visit among its page views and events in the visit log.
 */
class VisitorDetails extends VisitorDetailsAbstract
{
    public const ACTION_TYPE = 'error';

    public function provideActionsForVisitIds(&$actions, $visitIds)
    {
        foreach ((new LogError())->getErrorsOfVisits($visitIds) as $error) {
            $location = $error['source'];
            if ($error['line_number'] > 0) {
                $location .= ':' . $error['line_number'] . ':' . $error['column_number'];
            }

            $actions[$error['idvisit']][] = [
                'type' => self::ACTION_TYPE,
                'serverTimePretty' => $error['server_time'],
                'title' => Piwik::translate('RebelErrorTracking_Type_' . $error['type']),
                'subtitle' => $error['message'],
                'errorType' => $error['type'],
                'errorMessage' => $error['message'],
                'errorLocation' => $location,
            ];
        }
    }

    public function renderAction($action, $previousAction, $visitorDetails)
    {
        if (($action['type'] ?? '') !== self::ACTION_TYPE) {
            return '';
        }

        $view = new View('@RebelErrorTracking/_actionError.twig');
        $view->sendHeadersWhenRendering = false;
        $view->action = $action;

        return $view->render();
    }
}
