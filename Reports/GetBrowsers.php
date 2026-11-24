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
use Piwik\Plugins\RebelErrorTracking\Categories\ErrorsCategory;

class GetBrowsers extends Report
{
    protected function init()
    {
        parent::init();

        $this->name = Piwik::translate('RebelErrorTracking_ErrorsByBrowser');
        $this->documentation = Piwik::translate('RebelErrorTracking_ErrorsByBrowserDocumentation');
        $this->categoryId = ErrorsCategory::ID;
        $this->subcategoryId = 'General_Overview';
        $this->metrics = ['nb_errors', 'nb_visits'];
        $this->defaultSortColumn = 'nb_errors';
        $this->order = 4;
    }

    public function configureView(ViewDataTable $view)
    {
        $view->config->columns_to_display = ['label', 'nb_errors', 'nb_visits'];
        $view->config->addTranslation('label', Piwik::translate('RebelErrorTracking_ColumnBrowser'));
        $view->config->show_exclude_low_population = false;
        $view->requestConfig->filter_sort_column = 'nb_errors';
        $view->requestConfig->filter_sort_order = 'desc';
    }
}
