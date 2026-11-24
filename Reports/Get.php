<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelErrorTracking\Reports;

use Piwik\Piwik;
use Piwik\Plugin\Report;
use Piwik\Plugin\ViewDataTable;
use Piwik\Plugins\CoreVisualizations\Visualizations\JqplotGraph\Evolution;
use Piwik\Plugins\RebelErrorTracking\Categories\ErrorsCategory;

class Get extends Report
{
    protected function init()
    {
        parent::init();

        $this->name = Piwik::translate('RebelErrorTracking_ErrorsOverTime');
        $this->documentation = Piwik::translate('RebelErrorTracking_ErrorsOverTimeDocumentation');
        $this->categoryId = ErrorsCategory::ID;
        $this->subcategoryId = 'General_Overview';
        $this->metrics = ['nb_errors', 'nb_visits_with_errors'];
        $this->order = 1;
    }

    public function getDefaultTypeViewDataTable()
    {
        return Evolution::ID;
    }

    public function configureView(ViewDataTable $view)
    {
        $view->config->columns_to_display = ['nb_errors'];
        $view->config->selectable_columns = ['nb_errors', 'nb_visits_with_errors'];
    }
}
