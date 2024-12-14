<?php

namespace App\Core\Metrics;

class MetricsValidator
{
    private array $thresholds;
    private SecurityManager $security;

    public function __construct(SecurityManager $security)
    {
        $this->security = $security;
        $this->thresholds = config('metrics.thresholds');
    }

    public function validateOperation(Operation $operation): void
    {
        if (!$this->security->validateOperationType($operation->getDetails()['type'])) {
            throw new ValidationException('Invalid operation type');
        }

        foreach ($operation->getMetricsRequirements() as $requirement => $value) {
            if (!$this->validateRequirement($requirement, $value)) {
                throw new ValidationException("Invalid requirement: {$requirement}");
            }
        }
    }

    public function validateMetrics(array $metrics, array $requirements): void
    {
        // Validate core metrics presence
        $this->validateCoreMetrics($metrics);

        // Validate metrics against thresholds
        $this->validateThresholds($metrics);

        // Validate security metrics
        $this->validateSecurityMetrics($metrics['security']);

        // Validate custom requirements
        $this->validateCustomRequirements($metrics, $requirements);
    }

    private function validateCoreMetrics(array $metrics): void
    {
        $requiredMetrics = ['timestamp', 'system', 'performance', 'security'];
        foreach ($requiredMetrics as $required) {
            if (!isset($metrics[$required])) {
                throw new ValidationException("Missing required metric: {$required}");
            }
        }
    }

    private function validateThresholds(array $metrics): void
    {
        // Validate memory usage
        if ($metrics['system']['memory'] > $this->thresholds['memory_limit']) {
            throw new ValidationException('Memory usage exceeds threshold');
        }

        // Validate CPU load
        if ($metrics['system']['cpu'][0] > $this->thresholds['cpu_limit']) {
            throw new ValidationException('CPU usage exceeds threshold');
        }

        // Validate response time
        if ($metrics['performance']['response_time']['p95'] > $this->thresholds['response_time_limit']) {
            throw new ValidationException('Response time exceeds threshold');
        }

        // Validate error rate
        if ($metrics['performance']['error_rate']['error_percentage'] > $this->thresholds['error_rate_limit']) {
            throw new ValidationException('Error rate exceeds threshold');
        }
    }

    private function validateSecurityMetrics(array $securityMetrics): void
    {
        if ($securityMetrics['threat_level'] > $this->thresholds['max_threat_level']) {
            throw new SecurityException('Security threat level exceeds threshold');
        }

        if ($securityMetrics['validation_failures'] > $this->thresholds['max_validation_failures']) {
            throw new SecurityException('Validation failures exceed threshold');
        }
    }

    private function validateCustomRequirements(array $metrics, array $requirements): void
    {
        foreach ($requirements as $metric => $requirement) {
            if (!$this->validateMetricRequirement($metrics, $metric, $requirement)) {
                throw new ValidationException("Metric does not meet requirement: {$metric}");
            }
        }
    }
}

class PerformanceAnalyzer
{
    private MetricsStore $store;
    private array $config;
    private array $baselineMetrics;

    public function __construct(MetricsStore $store)
    {
        $this->store = $store;
        $this->config = config('metrics.analysis');
        $this->baselineMetrics = $this->loadBaselineMetrics();
    }

    public function analyzeMetrics(array $metrics): AnalysisResult
    {
        $analysis = [
            'performance_score' => $this->calculatePerformanceScore($metrics),
            'health_status' => $this->determineHealthStatus($metrics),
            'anomalies' => $this->detectAnomalies($metrics),
            'trends' => $this->analyzeTrends($metrics),
            'recommendations' => $this->generateRecommendations($metrics)
        ];

        return new AnalysisResult($analysis);
    }

    public function getAverageResponseTime(): float
    {
        return $this->store->getAggregatedMetric('response_time', 'avg');
    }

    public function getMaxResponseTime(): float
    {
        return $this->store->getAggregatedMetric('response_time', 'max');
    }

    public function getMinResponseTime(): float
    {
        return $this->store->getAggregatedMetric('response_time', 'min');
    }

    public function getP95ResponseTime(): float
    {
        return $this->store->getAggregatedMetric('response_time', 'p95');
    }

    public function getCurrentThroughput(): int
    {
        return $this->store->getCurrentMetric('requests_per_second');
    }

    public function getCurrentBandwidth(): int
    {
        return $this->store->getCurrentMetric('bytes_per_second');
    }

    public function getConcurrentRequests(): int
    {
        return $this->store->getCurrentMetric('concurrent_requests');
    }

    public function getTotalErrors(): int
    {
        return $this->store->getAggregatedMetric('errors', 'sum');
    }

    public function getErrorPercentage(): float
    {
        $total = $this->store->getAggregatedMetric('requests', 'sum');
        $errors = $this->getTotalErrors();
        return $total > 0 ? ($errors / $total) * 100 : 0;
    }

    public function getErrorBreakdown(): array
    {
        return $this->store->getMetricBreakdown('error_types');
    }

    private function calculatePerformanceScore(array $metrics): float 
    {
        $weights = $this->config['score_weights'];
        $score = 0;

        $score += $this->calculateResponseTimeScore($metrics) * $weights['response_time'];
        $score += $this->calculateThroughputScore($metrics) * $weights['throughput'];
        $score += $this->calculateErrorScore($metrics) * $weights['error_rate'];
        $score += $this->calculateResourceScore($metrics) * $weights['resources'];

        return min(100, max(0, $score));
    }

    private function determineHealthStatus(array $metrics): string
    {
        $score = $this->calculatePerformanceScore($metrics);
        
        return match(true) {
            $score >= 90 => 'OPTIMAL',
            $score >= 70 => 'HEALTHY',
            $score >= 50 => 'DEGRADED',
            default => 'CRITICAL'
        };
    }

    private function detectAnomalies(array $metrics): array
    {
        $anomalies = [];
        $threshold = $this->config['anomaly_threshold'];

        foreach ($metrics as $metric => $value) {
            $baseline = $this->baselineMetrics[$metric] ?? null;
            if ($baseline && abs($value - $baseline) > $threshold) {
                $anomalies[] = [
                    'metric' => $metric,
                    'value' => $value,
                    'baseline' => $baseline,
                    'deviation' => abs($value - $baseline)
                ];
            }
        }

        return $anomalies;
    }

    private function loadBaselineMetrics(): array
    {
        return $this->store->getBaselineMetrics(
            $this->config['baseline_period']
        );
    }
}
