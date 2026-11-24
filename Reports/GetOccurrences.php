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

class GetOccurrences extends Report
{
    protected function init()
    {
        parent::init();

        $this->name = Piwik::translate('RebelErrorTracking_Occurrences');
        $this->categoryId = ErrorsCategory::ID;
        $this->metrics = [];
        $this->isSubtableReport = true;
        $this->supportsFlatten = false;
        $this->order = 2;
    }

    public function configureView(ViewDataTable $view)
    {
        $view->config->columns_to_display = ['label', 'page_url', 'location', 'stack'];
        $view->config->addTranslations([
            'label' => Piwik::translate('RebelErrorTracking_ColumnTime'),
            'page_url' => Piwik::translate('RebelErrorTracking_ColumnPage'),
            'location' => Piwik::translate('RebelErrorTracking_ColumnLocation'),
            'stack' => Piwik::translate('RebelErrorTracking_ColumnStack'),
        ]);

        $view->config->disable_row_evolution = true;
        $view->config->show_search = false;
        $view->config->show_exclude_low_population = false;
        $view->requestConfig->filter_sort_column = 'label';
        $view->requestConfig->filter_sort_order = 'desc';
    }
}
