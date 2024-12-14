<?php

namespace App\Core\Notification\Analytics\Services;

use App\Core\Notification\Analytics\Models\NotificationDeliveryMetrics;
use App\Core\Notification\Analytics\Events\MetricsProcessedEvent;
use App\Core\Notification\Analytics\Repositories\AnalyticsRepository;
use Illuminate\Support\Facades\Event;
use Carbon\Carbon;

class MetricsProcessorService
{
    private AnalyticsRepository $repository;
    private MetricsAggregator $aggregator;

    public function __construct(
        AnalyticsRepository $repository,
        MetricsAggregator $aggregator
    ) {
        $this->repository = $repository;
        $this->aggregator = $aggregator;
    }

    public function processDeliveryMetrics(NotificationDeliveryMetrics $metrics): array
    {
        $processedMetrics = [
            'delivery_time' => $metrics->getDeliveryTime(),
            'time_to_open' => $metrics->getTimeToOpen(),
            'time_to_click' => $metrics->getTimeToClick(),
            'time_to_convert' => $metrics->getTimeToConvert(),
            'total_journey_time' => $metrics->getTotalJourneyTime(),
            'engagement_score' => $this->calculateEngagementScore($metrics),
            'conversion_rate' => $this->calculateConversionRate($metrics),
            'processed_at' => Carbon::now()
        ];

        // Store processed metrics
        $this->repository->storeProcessedMetrics(
            $metrics->notification_id,
            $processedMetrics
        );

        // Aggregate metrics
        $this->aggregator->aggregate([
            'type' => $metrics->type,
            'metrics' => $processedMetrics
        ]);

        // Dispatch event
        Event::dispatch(new MetricsProcessedEvent($metrics, $processedMetrics));

        return $processedMetrics;
    }

    private function calculateEngagementScore(NotificationDeliveryMetrics $metrics): float
    {
        $score = 0;

        // Delivery score (30%)
        if ($metrics->isDelivered()) {
            $score += 30;
        }

        // Open score (30%)
        if ($metrics->isOpened()) {
            $score += 30;
        }

        // Click score (20%)
        if ($metrics->isClicked()) {
            $score += 20;
        }

        // Conversion score (20%)
        if ($metrics->isConverted()) {
            $score += 20;
        }

        return $score;
    }

    private function calculateConversionRate(NotificationDeliveryMetrics $metrics): float
    {
        if (!$metrics->isDelivered()) {
            return 0.0;
        }

        $stages = [
            $metrics->isDelivered(),
            $metrics->isOpened(),
            $metrics->isClicked(),
            $metrics->isConverted()
        ];

        $completedStages = count(array_filter($stages));
        return ($completedStages / count($stages)) * 100;
    }

    public function getMetricsSummary(string $type, Carbon $startDate, Carbon $endDate): array
    {
        $metrics = $this->repository->getAnalyticsByType($type, $startDate, $endDate);

        return [
            'total_notifications' => $metrics->count(),
            'delivery_rate' => $this->calculateDeliveryRate($metrics),
            'average_delivery_time' => $this->calculateAverageDeliveryTime($metrics),
            'open_rate' => $this->calculateOpenRate($metrics),
            'click_through_rate' => $this->calculateClickThroughRate($metrics),
            'conversion_rate' => $this->calculateOverallConversionRate($metrics),
            'average_engagement_score' => $this->calculateAverageEngagementScore($metrics),
            'time_period' => [
                'start' => $startDate->toDateTimeString(),
                'end' => $endDate->toDateTimeString()
            ]
        ];
    }

    private function calculateDeliveryRate($metrics): float
    {
        $delivered = $metrics->filter(fn($m) => $m->isDelivered())->count();
        return $metrics->count() > 0 ? ($delivered / $metrics->count()) * 100 : 0;
    }

    private function calculateAverageDeliveryTime($metrics): float
    {
        $deliveryTimes = $metrics
            ->filter(fn($m) => $m->isDelivered())
            ->map(fn($m) => $m->getDeliveryTime())
            ->filter();

        return $deliveryTimes->count() > 0 ? $deliveryTimes->avg() : 0;
    }

    private function calculateOpenRate($metrics): float
    {
        $opened = $metrics->filter(fn($m) => $m->isOpened())->count();
        $delivered = $metrics->filter(fn($m) => $m->isDelivered())->count();
        
        return $delivered > 0 ? ($opened / $delivered) * 100 : 0;
    }

    private function calculateClickThroughRate($metrics): float
    {
        $clicked = $metrics->filter(fn($m) => $m->isClicked())->count();
        $opened = $metrics->filter(fn($m) => $m->isOpened())->count();
        
        return $opened > 0 ? ($clicked / $opened) * 100 : 0;
    }

    private function calculateOverallConversionRate($metrics): float
    {
        $converted = $metrics->filter(fn($m) => $m->isConverted())->count();
        $delivered = $metrics->filter(fn($m) => $m->isDelivered())->count();
        
        return $delivered > 0 ? ($converted / $delivered) * 100 : 0;
    }

    private function calculateAverageEngagementScore($metrics): float
    {
        return $metrics->avg('engagement_score') ?? 0;
    }
}
