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
use Piwik\Report\ReportWidgetFactory;
use Piwik\Widget\WidgetsList;

class GetErrors extends Report
{
    protected function init()
    {
        parent::init();

        $this->name = Piwik::translate('RebelErrorTracking_Errors');
        $this->documentation = Piwik::translate('RebelErrorTracking_ErrorsReportDocumentation');
        $this->categoryId = ErrorsCategory::ID;
        $this->subcategoryId = 'General_Overview';
        $this->metrics = ['nb_errors', 'nb_visits'];
        $this->defaultSortColumn = 'nb_errors';
        $this->actionToLoadSubTables = 'getOccurrences';
        $this->supportsFlatten = false;
        $this->order = 2;
    }

    public function configureWidgets(WidgetsList $widgetsList, ReportWidgetFactory $factory)
    {
        $widgetsList->addWidgetConfig($factory->createWidget()->setIsWide());
    }

    public function configureView(ViewDataTable $view)
    {
        $view->config->columns_to_display = ['label', 'type', 'source', 'nb_errors', 'nb_visits', 'last_seen'];
        $view->config->addTranslations([
            'label' => Piwik::translate('RebelErrorTracking_ColumnMessage'),
            'type' => Piwik::translate('RebelErrorTracking_ColumnType'),
            'source' => Piwik::translate('RebelErrorTracking_ColumnSource'),
            'nb_errors' => Piwik::translate('RebelErrorTracking_ColumnErrors'),
            'last_seen' => Piwik::translate('RebelErrorTracking_ColumnLastSeen'),
        ]);

        $view->requestConfig->filter_sort_column = 'nb_errors';
        $view->requestConfig->filter_sort_order = 'desc';

        $view->config->show_all_views_icons = false;
        $view->config->show_exclude_low_population = false;
        $view->config->show_flatten_table = false;
    }
}
