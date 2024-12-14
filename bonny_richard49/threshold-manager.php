<?php

namespace App\Core\Security\Services;

use Illuminate\Support\Facades\Cache;
use App\Core\Interfaces\ThresholdInterface;
use App\Core\Security\Events\ThresholdEvent;

class ThresholdManager implements ThresholdInterface
{
    private AlertService $alerts;
    private MetricsCollector $metrics;
    private array $config;
    private array $thresholds;

    private const CACHE_PREFIX = 'threshold:';
    private const CACHE_TTL = 300;
    private const MAX_HISTORY = 1000;

    public function __construct(
        AlertService $alerts,
        MetricsCollector $metrics,
        array $config
    ) {
        $this->alerts = $alerts;
        $this->metrics = $metrics;
        $this->config = $config;
        $this->thresholds = $this->loadThresholds();
    }

    public function isExceeded(string $metric, $value): bool
    {
        $threshold = $this->getThreshold($metric);
        if (!$threshold) return false;

        $this->recordMetricValue($metric, $value);
        
        if ($this->exceedsThreshold($value, $threshold)) {
            $this->handleThresholdExceeded($metric, $value, $threshold);
            return true;
        }

        return false;
    }

    public function isSystemCritical(array $metrics): bool
    {
        $criticalCount = 0;
        
        foreach ($metrics as $metric => $value) {
            if ($this->isCriticalMetric($metric, $value)) {
                $criticalCount++;
            }
        }

        return $criticalCount >= $this->config['critical_threshold_count'];
    }

    public function calculateSeverity(array $data): string
    {
        $severity = 'info';
        $criticalMetrics = [];
        
        foreach ($data as $metric => $value) {
            if ($this->isCriticalMetric($metric, $value)) {
                $criticalMetrics[$metric] = $value;
                $severity = $this->escalateSeverity($severity);
            }
        }

        if ($severity !== 'info') {
            $this->recordCriticalState($criticalMetrics);
        }

        return $severity;
    }

    public function trackMetrics(array $metrics): void
    {
        foreach ($metrics as $metric => $value) {
            $this->trackMetric($metric, $value);
        }

        $this->analyzeMetricsTrends();
        $this->updateThresholdStatus();
    }

    public function exceedsThreshold(array $data): bool
    {
        $exceededThresholds = [];
        
        foreach ($data as $metric => $value) {
            $threshold = $this->getThreshold($metric);
            if ($threshold && $this->exceedsThreshold($value, $threshold)) {
                $exceededThresholds[$metric] = $value;
            }
        }

        if (!empty($exceededThresholds)) {
            $this->handleExceededThresholds($exceededThresholds);
            return true;
        }

        return false;
    }

    private function loadThresholds(): array
    {
        return array_merge(
            $this->config['default_thresholds'],
            $this->loadCustomThresholds()
        );
    }

    private function loadCustomThresholds(): array
    {
        $cached = Cache::get(self::CACHE_PREFIX . 'custom');
        if ($cached) return $cached;

        $thresholds = $this->loadThresholdsFromStorage();
        Cache::put(self::CACHE_PREFIX . 'custom', $thresholds, self::CACHE_TTL);
        
        return $thresholds;
    }

    private function getThreshold(string $metric): ?array
    {
        return $this->thresholds[$metric] ?? null;
    }

    private function recordMetricValue(string $metric, $value): void
    {
        $history = $this->getMetricHistory($metric);
        $history[] = [
            'value' => $value,
            'timestamp' => microtime(true)
        ];

        if (count($history) > self::MAX_HISTORY) {
            array_shift($history);
        }

        $this->saveMetricHistory($metric, $history);
    }

    private function exceedsThreshold($value, array $threshold): bool
    {
        return match($threshold['type']) {
            'max' => $value > $threshold['value'],
            'min' => $value < $threshold['value'],
            'range' => $value < $threshold['min'] || $value > $threshold['max'],
            'pattern' => $this->matchesPattern($value, $threshold['pattern']),
            default => false
        };
    }

    private function handleThresholdExceeded(string $metric, $value, array $threshold): void
    {
        $event = new ThresholdEvent(
            $metric,
            $value,
            $threshold,
            $this->getMetricHistory($metric)
        );

        if ($threshold['severity'] === 'critical') {
            $this->alerts->sendCriticalAlert([
                'type' => 'threshold_exceeded',
                'metric' => $metric,
                'value' => $value,
                'threshold' => $threshold,
                'history' => $this->getMetricHistory($metric)
            ]);
        }

        $this->metrics->recordThresholdExceeded($metric, $value);
    }

    private function isCriticalMetric(string $metric, $value): bool
    {
        $threshold = $this->getThreshold($metric);
        return $threshold 
            && $threshold['severity'] === 'critical'
            && $this->exceedsThreshold($value, $threshold);
    }

    private function escalateSeverity(string $current): string
    {
        return match($current) {
            'info' => 'warning',
            'warning' => 'error',
            'error' => 'critical',
            default => $current
        };
    }

    private function recordCriticalState(array $metrics): void
    {
        $this->metrics->recordCriticalState($metrics);
        
        if ($this->shouldTriggerEmergency($metrics)) {
            $this->triggerEmergencyProtocols($metrics);
        }
    }

    private function trackMetric(string $metric, $value): void
    {
        $this->recordMetricValue($metric, $value);
        
        if ($this->detectAnomaly($metric, $value)) {
            $this->handleAnomaly($metric, $value);
        }

        $this->updateMetricStatus($metric, $value);
    }

    private function analyzeMetricsTrends(): void
    {
        foreach ($this->thresholds as $metric => $threshold) {
            $history = $this->getMetricHistory($metric);
            if ($this->detectTrend($history)) {
                $this->handleTrendDetected($metric, $history);
            }
        }
    }

    private function handleExceededThresholds(array $thresholds): void
    {
        $this->alerts->sendSystemAlert([
            'type' => 'multiple_thresholds_exceeded',
            'thresholds' => $thresholds,
            'timestamp' => microtime(true)
        ]);

        $this->metrics->recordMultipleThresholds($thresholds);
    }

    private function getMetricHistory(string $metric): array
    {
        return Cache::get(self::CACHE_PREFIX . "history:$metric", []);
    }

    private function saveMetricHistory(string $metric, array $history): void
    {
        Cache::put(
            self::CACHE_PREFIX . "history:$metric",
            $history,
            self::CACHE_TTL
        );
    }

    private function detectAnomaly(string $metric, $value): bool
    {
        $history = $this->getMetricHistory($metric);
        if (count($history) < 10) return false;

        return $this->calculateZScore($value, $history) > 3;
    }

    private function detectTrend(array $history): bool
    {
        if (count($history) < 20) return false;

        $values = array_column($history, 'value');
        return $this->calculateTrendStrength($values) > 0.7;
    }

    private function calculateZScore($value, array $history): float
    {
        $values = array_column($history, 'value');
        $mean = array_sum($values) / count($values);
        $stdDev = $this->calculateStdDev($values, $mean);
        
        return abs($value - $mean) / ($stdDev ?: 1);
    }

    private function calculateStdDev(array $values, float $mean): float
    {
        $variance = array_reduce(
            $values,
            fn($carry, $value) => $carry + pow($value - $mean, 2),
            0
        ) / count($values);

        return sqrt($variance);
    }

    private function calculateTrendStrength(array $values): float
    {
        $n = count($values);
        $x = range(0, $n - 1);
        $y = array_values($values);

        $meanX = array_sum($x) / $n;
        $meanY = array_sum($y) / $n;

        $numerator = array_sum(array_map(
            fn($i) => ($x[$i] - $meanX) * ($y[$i] - $meanY),
            range(0, $n - 1)
        ));

        $denominator = sqrt(
            array_sum(array_map(
                fn($i) => pow($x[$i] - $meanX, 2),
                range(0, $n - 1)
            )) *
            array_sum(array_map(
                fn($i) => pow($y[$i] - $meanY, 2),
                range(0, $n - 1)
            ))
        );

        return $denominator ? abs($numerator / $denominator) : 0;
    }
}
