```php
namespace App\Core\Metrics;

class MetricsAnalysisSystem
{
    private MetricsCollector $collector;
    private AnalysisEngine $analyzer;
    private ThresholdManager $thresholds;

    public function analyzeSystemMetrics(): AnalysisResult
    {
        DB::transaction(function() {
            $metrics = $this->collectMetrics();
            $analysis = $this->performAnalysis($metrics);
            $this->validateAnalysis($analysis);
            return $this->generateResult($analysis);
        });
    }

    private function collectMetrics(): array
    {
        $metrics = $this->collector->collectCriticalMetrics();
        $this->validateMetricsCollection($metrics);
        return $metrics;
    }

    private function performAnalysis(array $metrics): Analysis
    {
        return $this->analyzer->analyze($metrics);
    }

    private function validateAnalysis(Analysis $analysis): void
    {
        if (!$this->thresholds->validateAgainstThresholds($analysis)) {
            throw new AnalysisException("Metrics analysis failed threshold validation");
        }
    }
}
```
