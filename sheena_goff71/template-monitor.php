namespace App\Core\Template\Performance;

class TemplatePerformanceMonitor 
{
    private MetricsCollector $metrics;
    private array $thresholds;
    private array $measurements = [];

    public function startMeasurement(string $template): void 
    {
        $this->measurements[$template] = [
            'start' => hrtime(true),
            'memory_start' => memory_get_usage(true)
        ];
    }

    public function endMeasurement(string $template): array 
    {
        $end = hrtime(true);
        $memoryEnd = memory_get_usage(true);
        $start = $this->measurements[$template]['start'];
        $memoryStart = $this->measurements[$template]['memory_start'];

        $metrics = [
            'duration' => ($end - $start) / 1e9,
            'memory' => $memoryEnd - $memoryStart
        ];

        $this->validateMetrics($template, $metrics);
        $this->collectMetrics($template, $metrics);

        return $metrics;
    }

    private function validateMetrics(string $template, array $metrics): void 
    {
        if ($metrics['duration'] > $this->thresholds['max_duration']) {
            throw new PerformanceException("Template $template exceeded maximum execution time");
        }

        if ($metrics['memory'] > $this->thresholds['max_memory']) {
            throw new PerformanceException("Template $template exceeded memory limit");
        }
    }

    private function collectMetrics(string $template, array $metrics): void 
    {
        $this->metrics->record("template.$template", $metrics);
    }
}
