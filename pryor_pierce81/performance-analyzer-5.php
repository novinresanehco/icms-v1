<?php

namespace App\Core\Performance;

use Illuminate\Support\Facades\Cache;
use App\Core\Contracts\AnalyzerInterface;
use App\Core\Exceptions\{PerformanceException, ThresholdException};

class PerformanceAnalyzer implements AnalyzerInterface
{
    private MetricsStore $store;
    private ThresholdValidator $validator;
    private AlertSystem $alerts;
    private array $thresholds;

    public function __construct(
        MetricsStore $store,
        ThresholdValidator $validator,
        AlertSystem $alerts
    ) {
        $this->store = $store;
        $this->validator = $validator;
        $this->alerts = $alerts;
        $this->thresholds = config('performance.thresholds');
    }

    public function analyzePerformance(array $metrics): AnalysisResult
    {
        DB::beginTransaction();

        try {
            // Validate metrics integrity
            $this->validateMetrics($metrics);

            // Performance analysis
            $analysis = $this->performAnalysis($metrics);
            
            // Threshold validation
            $this->validateThresholds($analysis);
            
            // Store analysis results
            $analysisId = $this->storeAnalysis($analysis);

            DB::commit();

            return new AnalysisResult([
                'id' => $analysisId,
                'metrics' => $metrics,
                'analysis' => $analysis,
                'timestamp' => now()
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleAnalysisFailure($e, $metrics);
            throw new PerformanceException('Performance analysis failed', 0, $e);
        }
    }

    private function validateMetrics(array $metrics): void
    {
        foreach (['response_time', 'throughput', 'error_rate'] as $required) {
            if (!isset($metrics[$required])) {
                throw new ValidationException("Missing required metric: {$required}");
            }
        }

        if (!$this->validator->validateMetricsIntegrity($metrics)) {
            throw new ValidationException('Metrics integrity validation failed');
        }
    }

    private function performAnalysis(array $metrics): array
    {
        return [
            'response_time' => $this->analyzeResponseTime($metrics['response_time']),
            'throughput' => $this->analyzeThroughput($metrics['throughput']),
            'error_rate' => $this->analyzeErrorRate($metrics['error_rate']),
            'resource_usage' => $this->analyzeResourceUsage($metrics['resources']),
            'system_health' => $this->analyzeSystemHealth($metrics['system'])
        ];
    }

    private function validateThresholds(array $analysis): void
    {
        foreach ($analysis as $metric => $data) {
            if (isset($this->thresholds[$metric])) {
                $threshold = $this->thresholds[$metric];
                
                if (!$this->validator->validateThreshold($data, $threshold)) {
                    $this->handleThresholdViolation($metric, $data, $threshold);
                }
            }
        }
    }

    private function analyzeResponseTime(array $responseData): array
    {
        return [
            'average' => $this->calculateMovingAverage($responseData),
            'percentiles' => $this->calculatePercentiles($responseData),
            'trend' => $this->analyzeTrend($responseData),
            'anomalies' => $this->detectAnomalies($responseData)
        ];
    }

    private function analyzeThroughput(array $throughputData): array
    {
        return [
            'current_rate' => $throughputData['current'],
            'peak_rate' => $throughputData['peak'],
            'saturation_point' => $this->calculateSaturationPoint($throughputData),
            'bottlenecks' => $this->identifyBottlenecks($throughputData)
        ];
    }

    private function analyzeErrorRate(array $errorData): array
    {
        return [
            'current_rate' => $errorData['current'],
            'trend' => $this->analyzeTrend($errorData['history']),
            'patterns' => $this->identifyErrorPatterns($errorData),
            'impact' => $this->assessErrorImpact($errorData)
        ];
    }

    private function handleThresholdViolation(string $metric, $value, $threshold): void
    {
        $severity = $this->calculateViolationSeverity($value, $threshold);
        
        $this->alerts->sendThresholdAlert([
            'metric' => $metric,
            'value' => $value,
            'threshold' => $threshold,
            'severity' => $severity
        ]);

        if ($severity === 'critical') {
            throw new ThresholdException(
                "Critical threshold violation for {$metric}"
            );
        }
    }

    private function calculateMovingAverage(array $data): float
    {
        $window = array_slice($data, -config('performance.moving_average_window'));
        return array_sum($window) / count($window);
    }

    private function calculatePercentiles(array $data): array
    {
        sort($data);
        $count = count($data);
        
        return [
            'p50' => $data[(int)($count * 0.5)],
            'p90' => $data[(int)($count * 0.9)],
            'p95' => $data[(int)($count * 0.95)],
            'p99' => $data[(int)($count * 0.99)]
        ];
    }

    private function analyzeTrend(array $data): array
    {
        $trend = $this->calculateLinearRegression($data);
        
        return [
            'slope' => $trend['slope'],
            'direction' => $trend['slope'] > 0 ? 'increasing' : 'decreasing',
            'significance' => $this->calculateTrendSignificance($trend)
        ];
    }

    private function detectAnomalies(array $data): array
    {
        $anomalies = [];
        $mean = array_sum($data) / count($data);
        $stdDev = $this->calculateStdDev($data, $mean);
        
        foreach ($data as $timestamp => $value) {
            if (abs($value - $mean) > $stdDev * 3) {
                $anomalies[] = [
                    'timestamp' => $timestamp,
                    'value' => $value,
                    'deviation' => ($value - $mean) / $stdDev
                ];
            }
        }
        
        return $anomalies;
    }

    private function handleAnalysisFailure(\Exception $e, array $metrics): void
    {
        $this->alerts->sendCriticalAlert([
            'type' => 'PerformanceAnalysis',
            'error' => $e->getMessage(),
            'metrics' => $metrics,
            'timestamp' => now()
        ]);

        $this->store->storeFailure([
            'exception' => $e,
            'metrics' => $metrics,
            'context' => $this->captureContext()
        ]);
    }
}
