<?php

namespace App\Core\Notification\Analytics\RealTime;

use Illuminate\Support\Facades\Redis;
use App\Core\Notification\Analytics\Events\AnomalyDetected;

class RealTimeProcessor
{
    private const WINDOW_SIZE = 300; // 5 minutes
    private array $thresholds;

    public function __construct()
    {
        $this->thresholds = config('analytics.realtime.thresholds');
    }

    public function processMetric(string $metric, float $value, array $context = [])
    {
        $timestamp = time();
        $key = "realtime:metrics:{$metric}";

        Redis::pipeline(function ($pipe) use ($key, $value, $timestamp, $context) {
            $pipe->zadd($key, $timestamp, json_encode([
                'value' => $value,
                'timestamp' => $timestamp,
                'context' => $context
            ]));
            $pipe->zremrangebyscore($key, '-inf', $timestamp - self::WINDOW_SIZE);
        });

        $this->detectAnomalies($metric, $value, $context);
    }

    public function getMetricTrend(string $metric, int $duration = 300): array
    {
        $key = "realtime:metrics:{$metric}";
        $minTimestamp = time() - $duration;

        $data = Redis::zrangebyscore($key, $minTimestamp, '+inf');
        return array_map(fn($item) => json_decode($item, true), $data);
    }

    public function calculateMetricStats(string $metric): array
    {
        $data = $this->getMetricTrend($metric);
        $values = array_column($data, 'value');

        return [
            'current' => end($values) ?: 0,
            'mean' => count($values) ? array_sum($values) / count($values) : 0,
            'min' => $values ? min($values) : 0,
            'max' => $values ? max($values) : 0,
            'std_dev' => $this->calculateStandardDeviation($values)
        ];
    }

    private function detectAnomalies(string $metric, float $value, array $context)
    {
        $stats = $this->calculateMetricStats($metric);
        $threshold = $this->thresholds[$metric] ?? $this->thresholds['default'];

        $zScore = abs($value - $stats['mean']) / ($stats['std_dev'] ?: 1);

        if ($zScore > $threshold) {
            event(new AnomalyDetected($metric, [
                'value' => $value,
                'z_score' => $zScore,
                'stats' => $stats,
                'context' => $context
            ]));
        }
    }

    private function calculateStandardDeviation(array $values): float
    {
        $count = count($values);
        if ($count < 2) {
            return 0.0;
        }

        $mean = array_sum($values) / $count;
        $squaredDiffs = array_map(fn($value) => pow($value - $mean, 2), $values);
        return sqrt(array_sum($squaredDiffs) / ($count - 1));
    }
}
