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
use Piwik\Plugins\CoreVisualizations\Visualizations\Sparklines;
use Piwik\Plugins\RebelErrorTracking\Categories\ErrorsCategory;
use Piwik\Report\ReportWidgetFactory;
use Piwik\Widget\WidgetsList;

class Get extends Report
{
    protected function init()
    {
        parent::init();

        $this->name = Piwik::translate('RebelErrorTracking_ErrorsOverTime');
        $this->documentation = Piwik::translate('RebelErrorTracking_ErrorsOverTimeDocumentation');
        $this->categoryId = ErrorsCategory::ID;
        $this->subcategoryId = 'General_Overview';
        $this->metrics = ['nb_errors', 'nb_new_errors', 'nb_visits_with_errors', 'error_rate'];
        $this->order = 1;
    }

    public function configureWidgets(WidgetsList $widgetsList, ReportWidgetFactory $factory)
    {
        $widgetsList->addWidgetConfig($factory->createWidget()->forceViewDataTable(Evolution::ID)->setIsWide()->setOrder(1));
        $widgetsList->addWidgetConfig(
            $factory->createWidget()
                ->setName('RebelErrorTracking_ErrorsInNumbers')
                ->forceViewDataTable(Sparklines::ID)
                ->setIsWide()
                ->setOrder(2)
        );
    }

    public function getDefaultTypeViewDataTable()
    {
        return Evolution::ID;
    }

    public function configureView(ViewDataTable $view)
    {
        if ($view instanceof Sparklines) {
            foreach ($this->metrics as $order => $metric) {
                $view->config->addSparklineMetric([$metric], $order);
            }

            return;
        }

        $view->config->columns_to_display = ['nb_errors'];
        $view->config->selectable_columns = $this->metrics;
    }
}
