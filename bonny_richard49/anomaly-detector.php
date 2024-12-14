<?php

namespace App\Core\Notification\Analytics\Services;

use App\Core\Notification\Analytics\Repositories\AnalyticsRepository;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class AnomalyDetectionService
{
    private AnalyticsRepository $repository;
    private array $thresholds;
    private array $baselineCache = [];

    public function __construct(AnalyticsRepository $repository)
    {
        $this->repository = $repository;
        $this->thresholds = config('notification.analytics.anomaly_thresholds', [
            'delivery_time' => [
                'warning' => 1.5,  // 50% above baseline
                'critical' => 2.0   // 100% above baseline
            ],
            'open_rate' => [
                'warning' => 0.7,   // 30% below baseline
                'critical' => 0.5    // 50% below baseline
            ],
            'click_rate' => [
                'warning' => 0.7,
                'critical' => 0.5
            ],
            'conversion_rate' => [
                'warning' => 0.7,
                'critical' => 0.5
            ]
        ]);
    }

    public function detectAnomaly(string $metricName, float $currentValue): ?array
    {
        if (!isset($this->thresholds[$metricName])) {
            return null;
        }

        $baseline = $this->getBaseline($metricName);
        if (!$baseline) {
            return null;
        }

        $deviation = $this->calculateDeviation($currentValue, $baseline);
        $severity = $this->determineSeverity($metricName, $deviation);

        if (!$severity) {
            return null;
        }

        return [
            'threshold' => $baseline * $this->thresholds[$metricName][$severity],
            'severity' => $severity,
            'context' => [
                'baseline' => $baseline,
                'current_value' => $currentValue,
                'deviation' => $deviation,
                'timestamp' => Carbon::now()
            ]
        ];
    }

    private function getBaseline(string $metricName): ?float
    {
        if (isset($this->baselineCache[$metricName])) {
            return $this->baselineCache[$metricName];
        }

        $cacheKey = "notification_analytics_baseline:{$metricName}";
        
        $baseline = Cache::remember($cacheKey, 3600, function () use ($metricName) {
            return $this->calculateBaseline($metricName);
        });

        $this->baselineCache[$metricName] = $baseline;
        return $baseline;
    }

    private function calculateBaseline(string $metricName): float
    {
        $endDate = Carbon::now();
        $startDate = $endDate->copy()->subDays(30);

        $metrics = $this->repository->getAggregatedMetrics(
            $metricName,
            'daily',
            $startDate,
            $endDate
        );

        if ($metrics->isEmpty()) {
            return 0;
        }

        return $metrics->avg('value');
    }

    private function calculateDeviation(float $currentValue, float $baseline): float
    {
        if ($baseline == 0) {
            return 0;
        }

        return abs($currentValue - $baseline) / $baseline;
    }

    private function determineSeverity(string $metricName, float $deviation): ?string
    {
        $thresholds = $this->thresholds[$metricName];

        if ($deviation >= $thresholds['critical']) {
            return 'critical';
        }

        if ($deviation >= $thresholds['warning']) {
            return 'warning';
        }

        return null;
    }

    public function updateBaseline(string $metricName): void
    {
        Cache::forget("notification_analytics_baseline:{$metricName}");
        unset($this->baselineCache[$metricName]);
        $this->getBaseline($metricName); // Recalculate and cache
    }
}
