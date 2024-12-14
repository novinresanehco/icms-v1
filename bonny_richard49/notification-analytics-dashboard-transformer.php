<?php

namespace App\Core\Notification\Analytics\Dashboard;

use App\Core\Notification\Analytics\NotificationAnalytics;
use App\Core\Notification\Analytics\RealTime\RealTimeProcessor;
use App\Core\Notification\Analytics\Cache\AnalyticsCacheStrategy;

class DashboardDataTransformer
{
    private NotificationAnalytics $analytics;
    private RealTimeProcessor $realTimeProcessor;
    private AnalyticsCacheStrategy $cache;

    public function __construct(
        NotificationAnalytics $analytics,
        RealTimeProcessor $realTimeProcessor,
        AnalyticsCacheStrategy $cache
    ) {
        $this->analytics = $analytics;
        $this->realTimeProcessor = $realTimeProcessor;
        $this->cache = $cache;
    }

    public function getOverviewData(): array
    {
        return $this->cache->rememberAnalytics('dashboard.overview', 300, function () {
            $performance = $this->analytics->analyzePerformance(['period' => 'today']);
            $realTime = $this->realTimeProcessor->calculateMetricStats('notifications.sent');

            return [
                'total_sent' => $performance['summary']['total_sent'],
                'delivery_rate' => $performance['summary']['delivery_rate'],
                'engagement_rate' => $performance['summary']['engagement_rate'],
                'current_rate' => $realTime['current'],
                'trending' => $this->calculateTrend($realTime)
            ];
        });
    }

    public function getChannelBreakdown(): array
    {
        return $this->cache->rememberAnalytics('dashboard.channels', 600, function () {
            $channels = $this->analytics->analyzeChannelEffectiveness();
            
            return array_map(function ($channel) {
                return [
                    'name' => $channel['name'],
                    'volume' => $channel['total_sent'],
                    'success_rate' => $channel['delivery_rate'],
                    'cost_efficiency' => $channel['cost_per_delivery'],
                    'performance_score' => $this->calculateChannelScore($channel)
                ];
            }, $channels);
        });
    }

    public function getSegmentInsights(): array
    {
        return $this->cache->rememberAnalytics('dashboard.segments', 900, function () {
            $segments = $this->analytics->analyzeUserSegments();
            
            return array_map(function ($segment) {
                return [
                    'name' => $segment['name'],
                    'users' => $segment['total_users'],
                    'engagement' => $segment['engagement_rate'],
                    'response_time' => $segment['avg_response_time'],
                    'recommendations' => $this->generateRecommendations($segment)
                ];
            }, $segments);
        });
    }

    public function getPerformanceMetrics(): array
    {
        return $this->cache->rememberAnalytics('dashboard.performance', 300, function () {
            $metrics = $this->analytics->getPerformanceMetrics();
            
            return [
                'delivery_time' => [
                    'current' => $metrics['current_delivery_time'],
                    'trend' => $this->calculateMetricTrend($metrics['delivery_time_history']),
                    'threshold' => $metrics['delivery_time_threshold']
                ],
                'success_rate' => [
                    'current' => $metrics['current_success_rate'],
                    'trend' => $this->calculateMetricTrend($metrics['success_rate_history']),
                    'threshold' => $metrics['success_rate_threshold']
                ],
                'error_rate' => [
                    'current' => $metrics['current_error_rate'],
                    'trend' => $this->calculateMetricTrend($metrics['error_rate_history']),
                    'threshold' => $metrics['error_rate_threshold']
                ]
            ];
        });
    }

    private function calculateTrend(array $data): array
    {
        $values = array_column($data['history'], 'value');
        $currentValue = end($values);
        $previousValue = prev($values);

        return [
            'direction' => $currentValue > $previousValue ? 'up' : 'down',
            'percentage' => $previousValue ? abs(($currentValue - $previousValue) / $previousValue * 100) : 0
        ];
    }

    private function calculateChannelScore(array $channel): float
    {
        $weights = [
            'delivery_rate' => 0.4,
            'cost_efficiency' => 0.3,
            'engagement_rate' => 0.3
        ];

        $score = 0;
        foreach ($weights as $metric => $weight) {
            $score += $channel[$metric] * $weight;
        }

        return round($score, 2);
    }

    private function generateRecommendations(array $segment): array
    {
        $recommendations = [];

        if ($segment['engagement_rate'] < 0.2) {
            $recommendations[] = [
                'type' => 'engagement',
                'priority' => 'high',
                'suggestion' => 'Consider personalized content strategy'
            ];
        }

        if ($segment['response_time'] > 24) {
            $recommendations[] = [
                'type' => 'timing',
                'priority' => 'medium',
                'suggestion' => 'Optimize delivery timing'
            ];
        }

        return $recommendations;
    }

    private function calculateMetricTrend(array $history): array
    {
        $values = array_values($history);
        $count = count($values);

        if ($count < 2) {
            return ['direction' => 'stable', 'change' => 0];
        }

        $recent = array_slice($values, -5);
        $slope = $this->calculateLinearRegression($recent);

        return [
            'direction' => $slope > 0 ? 'up' : ($slope < 0 ? 'down' : 'stable'),
            'change' => abs($slope),
            'significant' => abs($slope) > 0.1
        ];
    }

    private function calculateLinearRegression(array $values): float
    {
        $count = count($values);
        $x = range(1, $count);
        
        $sumX = array_sum($x);
        $sumY = array_sum($values);
        $sumXY = array_sum(array_map(function($x, $y) {
            return $x * $y;
        }, $x, $values));
        $sumXX = array_sum(array_map(function($x) {
            return $x * $x;
        }, $x));

        $slope = ($count * $sumXY - $sumX * $sumY) / ($count * $sumXX - $sumX * $sumX);
        
        return $slope;
    }
}
