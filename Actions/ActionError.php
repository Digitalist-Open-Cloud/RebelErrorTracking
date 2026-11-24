<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelErrorTracking\Actions;

use Piwik\Date;
use Piwik\Plugins\RebelErrorTracking\Dao\LogError;
use Piwik\Plugins\RebelErrorTracking\MeasurableSettings;
use Piwik\Plugins\RebelErrorTracking\Tracker\ErrorReport;
use Piwik\Tracker\Action;
use Piwik\Tracker\Request;
use Piwik\Tracker\Visitor;

/**
 * An error in the browser of a visitor. It is kept in the table of the plugin and not among the
 * page views and events of the visit.
 */
class ActionError extends Action
{
    public const TYPE_ERROR = 87;

    public const MAX_ERRORS_PER_VISIT = 100;

    public function __construct(Request $request)
    {
        parent::__construct(self::TYPE_ERROR, $request);
    }

    public static function shouldHandle(Request $request)
    {
        return array_key_exists(ErrorReport::PARAMETER, $request->getParams());
    }

    protected function getActionsToLookup()
    {
        return [];
    }

    public function record(Visitor $visitor, $idReferrerActionUrl, $idReferrerActionName)
    {
        $error = ErrorReport::fromRequestParams($this->request->getParams());
        $idSite = (int) $this->request->getIdSite();
        $idVisit = $visitor->getVisitorColumn('idvisit');
        $log = new LogError();

        if (
            $error === null
            || !(new MeasurableSettings($idSite))->accepts($error)
            || $log->countForVisit($idVisit) >= self::MAX_ERRORS_PER_VISIT
        ) {
            return;
        }

        $log->record($error + [
            'idsite' => $idSite,
            'idvisit' => $idVisit,
            'idvisitor' => $visitor->getVisitorColumn('idvisitor'),
            'server_time' => Date::factory((int) $this->request->getCurrentTimestamp())->getDatetime(),
        ]);
    }
}
