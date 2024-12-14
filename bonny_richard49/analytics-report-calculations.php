<?php

namespace App\Core\Notification\Analytics\Services;

trait AnalyticsCalculationTrait
{
    private function calculateSummaryMetrics($metrics): array
    {
        $totalNotifications = $metrics->sum('total_notifications');
        $totalDelivered = $metrics->sum('delivered');
        $totalOpened = $metrics->sum('opened');
        $totalClicked = $metrics->sum('clicked');
        $totalConverted = $metrics->sum('converted');

        return [
            'total_notifications' => $totalNotifications,
            'delivery_rate' => $this->calculateRate($totalDelivered, $totalNotifications),
            'open_rate' => $this->calculateRate($totalOpened, $totalDelivered),
            'click_rate' => $this->calculateRate($totalClicked, $totalOpened),
            'conversion_rate' => $this->calculateRate($totalConverted, $totalClicked),
            'average_delivery_time' => $metrics->avg('avg_delivery_time'),
            'engagement_score' => $this->calculateEngagementScore([
                'delivery_rate' => $this->calculateRate($totalDelivered, $totalNotifications),
                'open_rate' => $this->calculateRate($totalOpened, $totalDelivered),
                'click_rate' => $this->calculateRate($totalClicked, $totalOpened),
                'conversion_rate' => $this->calculateRate($totalConverted, $totalClicked)
            ])
        ];
    }

    private function calculatePerformanceAggregates($metrics): array
    {
        return [
            'overall_success_rate' => $this->calculateSuccessRate($metrics),
            'average_delivery_time' => $metrics->avg('avg_delivery_time'),
            'performance_score' => $this->calculatePerformanceScore($metrics),
            'failure_rate_by_type' => $this->calculateFailureRatesByType($metrics),
            'delivery_time_distribution' => $this->calculateDeliveryTimeDistribution($metrics)
        ];
    }

    private function calculateEngagementRates($metrics): array
    {
        $rates = [];
        foreach ($metrics->groupBy('type') as $type => $typeMetrics) {
            $total = $typeMetrics->sum('total');
            $rates[$type] = [
                'open_rate' => $this->calculateRate($typeMetrics->sum('opened'), $total),
                'click_rate' => $this->calculateRate($typeMetrics->sum('clicked'), $typeMetrics->sum('opened')),
                'conversion_rate' => $this->calculateRate($typeMetrics->sum('converted'), $typeMetrics->sum('clicked')),
                'engagement_score' => $this->calculateEngagementScore([
                    'open_rate' => $this->calculateRate($typeMetrics->sum('opened'), $total),
                    'click_rate' => $this->calculateRate($typeMetrics->sum('clicked'), $typeMetrics->sum('opened')),
                    'conversion_rate' => $this->calculateRate($typeMetrics->sum('converted'), $typeMetrics->sum('clicked'))
                ])
            ];
        }
        return $rates;
    }

    private function analyzeEngagementTrends($metrics): array
    {
        $trends = [];
        foreach ($metrics->groupBy('type') as $type => $typeMetrics) {
            $trends[$type] = [
                'open_rate_trend' => $this->calculateTrend($typeMetrics, 'opened', 'total'),
                'click_rate_trend' => $this->calculateTrend($typeMetrics, 'clicked', 'opened'),
                'conversion_rate_trend' => $this->calculateTrend($typeMetrics, 'converted', 'clicked'),
                'overall_trend' => $this->calculateOverallTrend($typeMetrics)
            ];
        }
        return $trends;
    }

    private function calculateRate(int $numerator, int $denominator): float
    {
        return $denominator > 0 ? round(($numerator / $denominator) * 100, 2) : 0;
    }

    private function calculateEngagementScore(array $rates): float
    {
        // Weighted scoring based on importance of each metric
        $weights = [
            'delivery_rate' => 0.2,
            'open_rate' => 0.3,
            'click_rate' => 0.25,
            'conversion_rate' => 0.25
        ];

        $score = 0;
        foreach ($rates as $metric => $rate) {
            if (isset($weights[$metric])) {
                $score += $rate * $weights[$metric];
            }
        }

        return round($score, 2);
    }

    private function calculateSuccessRate($metrics): float
    {
        $totalNotifications = $metrics->sum('total_notifications');
        $totalFailures = $metrics->sum('failures');

        return $this->calculateRate($totalNotifications - $totalFailures, $totalNotifications);
    }

    private function calculatePerformanceScore($metrics): float
    {
        $successRate = $this->calculateSuccessRate($metrics);
        $avgDeliveryTime = $metrics->avg('avg_delivery_time');
        $targetDeliveryTime = $this->config['target_delivery_time'] ?? 5.0; // seconds

        $deliveryTimeScore = max(0, 100 - (($avgDeliveryTime / $targetDeliveryTime) * 100));
        
        return round(($successRate * 0.7) + ($deliveryTimeScore * 0.3), 2);
    }

    private function calculateFailureRatesByType($metrics): array
    {
        $failureRates = [];
        foreach ($metrics as $metric) {
            $failureRates[$metric->type] = $this->calculateRate(
                $metric->failures,
                $metric->total_notifications
            );
        }
        return $failureRates;
    }

    private function calculateDeliveryTimeDistribution($metrics): array
    {
        $distribution = [
            'fast' => 0,    // < 1 second
            'normal' => 0,  // 1-5 seconds
            'slow' => 0,    // > 5 seconds
        ];

        foreach ($metrics as $metric) {
            $total = $metric->total_notifications;
            if ($metric->avg_delivery_time < 1) {
                $distribution['fast'] += $total;
            } elseif ($metric->avg_delivery_time <= 5) {
                $distribution['normal'] += $total;
            } else {
                $distribution['slow'] += $total;
            }
        }

        $totalNotifications = array_sum($distribution);
        if ($totalNotifications > 0) {
            array_walk($distribution, function(&$value) use ($totalNotifications) {
                $value = $this->calculateRate($value, $totalNotifications);
            });
        }

        return $distribution;
    }

    private function calculateTrend($metrics, string $numerator, string $denominator): array
    {
        $rates = collect($metrics)->map(function($metric) use ($numerator, $denominator) {
            return [
                'period' => $metric->period,
                'rate' => $this->calculateRate($metric->$numerator, $metric->$denominator)
            ];
        })->sortBy('period');

        $trend = [
            'direction' => $this->calculateTrendDirection($rates),
            'change_rate' => $this->calculateChangeRate($rates),
            'volatility' => $this->calculateVolatility($rates)
        ];

        $trend['analysis'] = $this->analyzeTrend($trend);
        return $trend;
    }

    private function calculateTrendDirection($rates): string
    {
        if ($rates->count() < 2) {
            return 'stable';
        }

        $firstRate = $rates->first()['rate'];
        $lastRate = $rates->last()['rate'];
        $difference = $lastRate - $firstRate;

        return match(true) {
            $difference > 1 => 'increasing',
            $difference < -1 => 'decreasing',
            default => 'stable'
        };
    }

    private function calculateChangeRate($rates): float
    {
        if ($rates->count() < 2) {
            return 0;
        }

        $firstRate = $rates->first()['rate'];
        $lastRate = $rates->last()['rate'];

        if ($firstRate == 0) {
            return $lastRate > 0 ? 100 : 0;
        }

        return round((($lastRate - $firstRate) / $firstRate) * 100, 2);
    }

    private function calculateVolatility($rates): float
    {
        if ($rates->count() < 2) {
            return 0;
        }

        $values = $rates->pluck('rate')->toArray();
        $mean = array_sum($values) / count($values);
        
        $squaredDiffs = array_map(function($value) use ($mean) {
            return pow($value - $mean, 2);
        }, $values);

        return round(sqrt(array_sum($squaredDiffs) / count($squaredDiffs)), 2);
    }

    private function analyzeTrend(array $trend): string
    {
        $analysis = match($trend['direction']) {
            'increasing' => $trend['change_rate'] > 10 ? 'Strong positive trend' : 'Moderate positive trend',
            'decreasing' => $trend['change_rate'] < -10 ? 'Strong negative trend' : 'Moderate negative trend',
            default => 'Stable trend'
        };

        if ($trend['volatility'] > 5) {
            $analysis .= ' with high volatility';
        } elseif ($trend['volatility'] > 2) {
            $analysis .= ' with moderate volatility';
        }

        return $analysis;
    }

    private function calculateOverallTrend($metrics): array
    {
        $engagementScores = collect($metrics)->map(function($metric) {
            return [
                'period' => $metric->period,
                'score' => $this->calculateEngagementScore([
                    'open_rate' => $this->calculateRate($metric->opened, $metric->total),
                    'click_rate' => $this->calculateRate($metric->clicked, $metric->opened),
                    'conversion_rate' => $this->calculateRate($metric->converted, $metric->clicked)
                ])
            ];
        });

        return [
            'trend' => $this->calculateTrendDirection($engagementScores),
            'change_rate' => $this->calculateChangeRate($engagementScores),
            'volatility' => $this->calculateVolatility($engagementScores)
        ];
    }
}
