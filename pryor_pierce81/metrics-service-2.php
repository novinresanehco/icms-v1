<?php

namespace App\Core\Metrics;

class MetricsService implements MetricsInterface
{
    private MetricsCollector $collector;
    private DataValidator $validator;
    private AnalysisEngine $analyzer;
    private AlertTrigger $alertTrigger;
    private MetricsStorage $storage;
    private ThresholdManager $thresholds;

    public function __construct(
        MetricsCollector $collector,
        DataValidator $validator,
        AnalysisEngine $analyzer,
        AlertTrigger $alertTrigger,
        MetricsStorage $storage,
        ThresholdManager $thresholds
    ) {
        $this->collector = $collector;
        $this->validator = $validator;
        $this->analyzer = $analyzer;
        $this->alertTrigger = $alertTrigger;
        $this->storage = $storage;
        $this->thresholds = $thresholds;
    }

    public function collectMetrics(MetricContext $context): MetricResult
    {
        $batchId = $this->initializeCollection($context);
        
        try {
            DB::beginTransaction();
            
            $rawMetrics = $this->collector->collect($context);
            $this->validateMetrics($rawMetrics);
            
            $analyzedMetrics = $this->analyzer->analyze($rawMetrics);
            $this->checkThresholds($analyzedMetrics);
            
            $result = new MetricResult([
                'batch_id' => $batchId,
                'metrics' => $analyzedMetrics,
                'timestamp' => now()
            ]);
            
            $this->storage->store($result);
            
            DB::commit();
            return $result;

        } catch (MetricException $e) {
            DB::rollBack();
            $this->handleMetricFailure($e, $batchId);
            throw new CriticalMetricException($e->getMessage(), $e);
        }
    }

    private function validateMetrics(array $metrics): void
    {
        $validationResult = $this->validator->validate($metrics);
        
        if (!$validationResult->isValid()) {
            throw new ValidationException(
                'Metric validation failed',
                $validationResult->getViolations()
            );
        }
    }

    private function checkThresholds(array $metrics): void
    {
        foreach ($metrics as $metric => $value) {
            if ($this->thresholds->isExceeded($metric, $value)) {
                $this->alertTrigger->triggerAlert(
                    new ThresholdAlert($metric, $value, $this->thresholds->getLimit($metric))
                );
            }
        }
    }

    private function handleMetricFailure(MetricException $e, string $batchId): void
    {
        $this->storage->logFailure($e, $batchId);
        
        $this->alertTrigger->triggerAlert(
            new MetricAlert(
                'Critical metric collection failure',
                [
                    'batch_id' => $batchId,
                    'exception' => $e
                ]
            )
        );
    }
}
