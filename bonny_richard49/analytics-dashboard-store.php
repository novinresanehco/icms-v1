<?php

namespace App\Core\Notification\Analytics\Store;

use App\Core\Notification\Analytics\Models\AnalyticsData;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class AnalyticsDashboardStore
{
    private const CACHE_KEY_PREFIX = 'analytics_dashboard:';
    private const CACHE_TTL = 300; // 5 minutes

    public function storeMetrics(array $metrics, string $timeframe): void
    {
        Cache::put(
            $this->getCacheKey($timeframe),
            $metrics,
            Carbon::now()->addSeconds(self::CACHE_TTL)
        );
    }

    public function getMetrics(string $timeframe): ?array
    {
        return Cache::get($this->getCacheKey($timeframe));
    }

    public function invalidateMetrics(string $timeframe): void
    {
        Cache::forget($this->getCacheKey($timeframe));
    }

    public function aggregateMetrics(array $rawMetrics): array
    {
        return [
            'deliveryMetrics' => $this->aggregateDeliveryMetrics($rawMetrics),
            'performanceMetrics' => $this->aggregatePerformanceMetrics($rawMetrics),
            'engagementMetrics' => $this->aggregateEngagementMetrics($rawMetrics)
        ];
    }

    private function aggregateDeliveryMetrics(array $metrics): array
    {
        $successRates = array_column($metrics, 'success_rate');
        $deliveryTimes = array_column($metrics, 'delivery_time');

        return [
            'successRate' => round(array_sum($successRates) / count($successRates), 2),
            'avgDeliveryTime' => round(array_sum($deliveryTimes) / count($deliveryTimes), 2),
            'trend' => $this->calculateTrend($successRates),
            'timeline' => $this->formatTimelineData($metrics)
        ];
    }

    private function aggregatePerformanceMetrics(array $metrics): array
    {
        $distribution = [
            ['name' => 'Fast (< 1s)', 'value' => 0],
            ['name' => 'Normal (1-5s)', 'value' => 0],
            ['name' => 'Slow (> 5s)', 'value' => 0]
        ];

        foreach ($metrics as $metric) {
            $time = $metric['delivery_time'];
            if ($time < 1) {
                $distribution[0]['value']++;
            } elseif ($time <= 5) {
                $distribution[1]['value']++;
            } else {
                $distribution[2]['value']++;
            }
        }

        return [
            'distribution' => $distribution,
            'avgResponseTime' => round(array_sum(array_column($metrics, 'delivery_time')) / count($metrics), 2)
        ];
    }

    private function aggregateEngagementMetrics(array $metrics): array
    {
        $stages = [
            ['stage' => 'Delivered', 'count' => 0],
            ['stage' => 'Opened', 'count' => 0],
            ['stage' => 'Clicked', 'count' => 0],
            ['stage' => 'Converted', 'count' => 0]
        ];

        foreach ($metrics as $metric) {
            $stages[0]['count'] += $metric['delivered_count'] ?? 0;
            $stages[1]['count'] += $metric['opened_count'] ?? 0;
            $stages[2]['count'] += $metric['clicked_count'] ?? 0;
            $stages[3]['count'] += $metric['converted_count'] ?? 0;
        }

        $engagementRate = $stages[0]['count'] > 0 
            ? round(($stages[3]['count'] / $stages[0]['count']) * 100, 2)
            : 0;

        return [
            'timeline' => $stages,
            'engagementRate' => $engagementRate,
            'trend' => $this->calculateTrend(array_column($metrics, 'engagement_rate'))
        ];
    }

    private function formatTimelineData(array $metrics): array
    {
        return array_map(function ($metric) {
            return [
                'timestamp' => Carbon::parse($metric['timestamp'])->format('Y-m-d H:i'),
                'successRate' => round($metric['success_rate'], 2),
                'deliveryTime' => round($metric['delivery_time'], 2)
            ];
        }, $metrics);
    }

    private function calculateTrend(array $values): float
    {
        if (count($values) < 2) {
            return 0;
        }

        $firstValue = $values[0];
        $lastValue = end($values);

        if ($firstValue == 0) {
            return $lastValue * 100;
        }

        return round((($lastValue - $firstValue) / $firstValue) * 100, 2);
    }

    private function getCacheKey(string $timeframe): string
    {
        return self::CACHE_KEY_PREFIX . $timeframe;
    }
}
