```php
namespace App\Core\Metrics;

class CriticalMetricsCollector implements MetricsCollectorInterface
{
    private MetricsStore $metricsStore;
    private RealTimeAnalyzer $realTimeAnalyzer;
    private ThresholdValidator $thresholdValidator;
    private AlertDispatcher $alertDispatcher;
    private EmergencyHandler $emergencyHandler;

    public function collectMetrics(MetricsContext $context): MetricsResult
    {
        $sessionId = $this->initializeCollection($context);
        
        try {
            // Real-time metrics collection
            $systemMetrics = $this->collectSystemMetrics($context);
            $performanceMetrics = $this->collectPerformanceMetrics($context);
            $qualityMetrics = $this->collectQualityMetrics($context);
            $securityMetrics = $this->collectSecurityMetrics($context);

            // Real-time analysis
            $analysisResult = $this->realTimeAnalyzer->analyze([
                'system' => $systemMetrics,
                'performance' => $performanceMetrics,
                'quality' => $qualityMetrics,
                'security' => $securityMetrics
            ]);

            // Threshold validation
            $this->validateThresholds(
                $analysisResult,
                $context->getThresholds()
            );

            // Store metrics
            $this->metricsStore->store([
                'sessionId' => $sessionId,
                'metrics' => $analysisResult,
                'context' => $context,
                'timestamp' => now()
            ]);

            return new MetricsResult(
                success: true,
                sessionId: $sessionId,
                metrics: $analysisResult
            );

        } catch (MetricsException $e) {
            $this->handleMetricsFailure($e, $sessionId);
            throw new CriticalMetricsException(
                "Critical metrics failure: {$e->getMessage()}",
                previous: $e
            );
        }
    }

    private function validateThresholds(
        AnalysisResult $result, 
        array $thresholds
    ): void {
        foreach ($result->getCriticalMetrics() as $metric) {
            if (!$this->thresholdValidator->validate($metric, $thresholds)) {
                $this->alertDispatcher->dispatchCriticalAlert(
                    new ThresholdAlert(
                        metric: $metric,
                        threshold: $thresholds[$metric->getName()],
                        value: $metric->getValue()
                    )
                );

                throw new ThresholdViolationException(
                    "Critical threshold violation for {$metric->getName()}"
                );
            }
        }
    }

    private function handleMetricsFailure(
        MetricsException $e,
        string $sessionId
    ): void {
        $this->emergencyHandler->handleCriticalFailure(
            new CriticalFailure(
                type: FailureType::METRICS,
                sessionId: $sessionId,
                exception: $e,
                timestamp: now()
            )
        );

        $this->metricsStore->recordFailure([
            'sessionId' => $sessionId,
            'type' => FailureType::METRICS,
            'exception' => $e,
            'timestamp' => now()
        ]);
    }
}
```
