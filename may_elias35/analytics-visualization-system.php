// File: app/Core/Analytics/Visualization/ChartGenerator.php
<?php

namespace App\Core\Analytics\Visualization;

class ChartGenerator
{
    protected array $generators;
    protected DataFormatter $formatter;
    protected ChartConfig $config;

    public function generate(array $data, string $type): Chart
    {
        $generator = $this->getGenerator($type);
        
        if (!$generator) {
            throw new UnsupportedChartException("Chart type not supported: {$type}");
        }

        $formattedData = $this->formatter->format($data, $type);
        return $generator->generate($formattedData);
    }

    public function addGenerator(string $type, ChartGenerator $generator): void
    {
        $this->generators[$type] = $generator;
    }

    protected function getGenerator(string $type): ?ChartGenerator
    {
        return $this->generators[$type] ?? null;
    }
}

// File: app/Core/Analytics/Visualization/DashboardGenerator.php
<?php

namespace App\Core\Analytics\Visualization;

class DashboardGenerator
{
    protected ChartGenerator $chartGenerator;
    protected LayoutManager $layoutManager;
    protected WidgetManager $widgetManager;

    public function generate(DashboardConfig $config): Dashboard
    {
        $layout = $this->layoutManager->createLayout($config->getLayout());
        
        foreach ($config->getWidgets() as $widget) {
            $chart = $this->chartGenerator->generate(
                $widget->getData(),
                $widget->getType()
            );
            
            $this->widgetManager->addWidget($layout, $widget, $chart);
        }

        return new Dashboard($layout);
    }
}
