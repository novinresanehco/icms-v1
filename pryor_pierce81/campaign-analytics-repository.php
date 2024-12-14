<?php

namespace App\Core\Repository;

use App\Models\CampaignAnalytics;
use App\Core\Events\CampaignAnalyticsEvents;
use App\Core\Exceptions\CampaignAnalyticsException;

class CampaignAnalyticsRepository extends BaseRepository
{
    protected function getModelClass(): string
    {
        return CampaignAnalytics::class;
    }

    /**
     * Track campaign event
     */
    public function trackEvent(array $data): void
    {
        try {
            $this->create([
                'campaign_id' => $data['campaign_id'],
                'contact_id' => $data['contact_id'] ?? null,
                'event_type' => $data['event_type'],
                'event_data' => $data['event_data'] ?? [],
                'metadata' => [
                    'ip_address' => request()->ip(),
                    'user_agent' => request()->userAgent(),
                    'timestamp' => now()
                ]
            ]);

            event(new CampaignAnalyticsEvents\EventTracked($data));

        } catch (\Exception $e) {
            throw new CampaignAnalyticsException(
                "Failed to track event: {$e->getMessage()}"
            );
        }
    }

    /**
     * Get campaign performance metrics
     */
    public function getPerformanceMetrics(int $campaignId, array $options = []): array
    {
        return Cache::tags($this->getCacheTags())->remember(
            $this->getCacheKey("performance.{$campaignId}", serialize($options)),
            300, // 5 minutes cache
            function() use ($campaignId, $options) {
                $query = $this->model->where('campaign_id', $campaignId);

                if (isset($options['from'])) {
                    $query->where('created_at', '>=', $options['from']);
                }

                if (isset($options['to'])) {
                    $query->where('created_at', '<=', $options['to']);
                }

                $events = $query->get();

                return [
                    'total_events' => $events->count(),
                    'unique_contacts' => $events->pluck('contact_id')->unique()->count(),
                    'event_breakdown' => $this->getEventBreakdown($events),
                    'engagement_trends' => $this->getEngagementTrends($events),
                    'conversion_metrics' => $this->getConversionMetrics($events)
                ];
            }
        );
    }

    /**
     * Get contact engagement analysis
     */
    public function getContactEngagement(int $campaignId, int $contactId): array
    {
        return Cache::tags($this->getCacheTags())->remember(
            $this->getCacheKey("engagement.{$campaignId}.{$contactId}"),
            300, // 5 minutes cache
            function() use ($campaignId, $contactId) {
                $events = $this->model
                    ->where('campaign_id', $campaignId)
                    ->where('contact_id', $contactId)
                    ->orderBy('created_at')
                    ->get();

                return [
                    'first_interaction' => $events->first()?->created_at,
                    'last_interaction' => $events->last()?->created_at,
                    'total_interactions' => $events->count(),
                    'interaction_history' => $events->map(function($event) {
                        return [
                            'type' => $event->event_type,
                            'data' => $event->event_data,
                            'timestamp' => $event->created_at
                        ];
                    })->toArray()
                ];
            }
        );
    }

    /**
     * Get event breakdown
     */
    protected function getEventBreakdown(Collection $events): array
    {
        return $events->groupBy('event_type')
            ->map(function($groupEvents) {
                return [
                    'count' => $groupEvents->count(),
                    'unique_contacts' => $groupEvents->pluck('contact_id')->unique()->count()
                ];
            })->toArray();
    }

    /**
     * Get engagement trends
     */
    protected function getEngagementTrends(Collection $events): array
    {
        return $events->groupBy(function($event) {
            return $event->created_at->format('Y-m-d');
        })->map(function($dayEvents) {
            return [
                'total_events' => $dayEvents->count(),
                'event_types' => $dayEvents->groupBy('event_type')
                    ->map(function($typeEvents) {
                        return $typeEvents->count();
                    })->toArray()
            ];
        })->toArray();
    }

    /**
     * Get conversion metrics
     */
    protected function getConversionMetrics(Collection $events): array
    {
        $totalRecipients = DB::table('campaign_delivery_queue')
            ->where('campaign_id', $events->first()?->campaign_id)
            ->count();

        $conversions = $events->where('event_type', 'conversion')->count();

        return [
            'total_recipients' => $totalRecipients,
            'total_conversions' => $conversions,
            'conversion_rate' => $totalRecipients > 0 
                ? ($conversions / $totalRecipients) * 100 
                : 0
        ];
    }

    /**
     * Generate campaign report
     */
    public function generateReport(int $campaignId, string $format = 'array'): mixed
    {
        try {
            $data = [
                'campaign' => DB::table('campaigns')->find($campaignId),
                'performance_metrics' => $this->getPerformanceMetrics($campaignId),
                'delivery_stats' => app(CampaignDeliveryRepository::class)
                    ->getDeliveryStatistics($campaignId),
                'engagement_metrics' => app(CampaignDeliveryRepository::class)
                    ->getEngagementMetrics($campaignId),
                'generated_at' => now()
            ];

            return match($format) {
                'array' => $data,
                'json' => json_encode($data, JSON_PRETTY_PRINT),
                'pdf' => $this->generatePdfReport($data),
                'csv' => $this->generateCsvReport($data),
                default => throw new CampaignAnalyticsException("Unsupported format: {$format}")
            };

        } catch (\Exception $e) {
            throw new CampaignAnalyticsException(
                "Failed to generate report: {$e->getMessage()}"
            );
        }
    }

    /**
     * Generate comparative analysis
     */
    public function generateComparativeAnalysis(array $campaignIds): array
    {
        try {
            $campaigns = [];
            foreach ($campaignIds as $campaignId) {
                $campaigns[$campaignId] = [
                    'performance' => $this->getPerformanceMetrics($campaignId),
                    'delivery' => app(CampaignDeliveryRepository::class)
                        ->getDeliveryStatistics($campaignId),
                    'engagement' => app(CampaignDeliveryRepository::class)
                        ->getEngagementMetrics($campaignId)
                ];
            }

            return [
                'campaigns' => $campaigns,
                'comparison' => $this->compareMetrics($campaigns),
                'recommendations' => $this->generateRecommendations($campaigns)
            ];

        } catch (\Exception $e) {
            throw new CampaignAnalyticsException(
                "Failed to generate comparative analysis: {$e->getMessage()}"
            );
        }
    }

    /**
     * Compare metrics across campaigns
     */
    protected function compareMetrics(array $campaigns): array
    {
        // Implementation of metric comparison logic
        return [
            'delivery_rates' => $this->compareDeliveryRates($campaigns),
            'engagement_rates' => $this->compareEngagementRates($campaigns),
            'conversion_rates' => $this->compareConversionRates($campaigns)
        ];
    }

    /**
     * Generate recommendations based on analysis
     */
    protected function generateRecommendations(array $campaigns): array
    {
        // Implementation of recommendation generation logic
        $recommendations = [];

        // Delivery optimization recommendations
        $recommendations['delivery'] = $this->generateDeliveryRecommendations($campaigns);

        // Content optimization recommendations
        $recommendations['content'] = $this->generateContentRecommendations($campaigns);

        // Timing optimization recommendations
        $recommendations['timing'] = $this->generateTimingRecommendations($campaigns);

        return $recommendations;
    }
}
