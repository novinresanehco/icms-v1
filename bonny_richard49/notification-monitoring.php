<?php

namespace App\Core\Notification\Monitoring;

use App\Core\Monitoring\MetricsCollector;
use App\Core\Monitoring\PerformanceTracker;
use App\Core\Notification\Models\Notification;
use Illuminate\Support\Facades\{Cache, Log};

class NotificationMonitor
{
    protected MetricsCollector $metrics;
    protected PerformanceTracker $performance;
    protected array $thresholds;

    public function __construct(MetricsCollector $metrics, PerformanceTracker $performance)
    {
        $this->metrics = $metrics;
        $this->performance = $performance;
        $this->thresholds = config('notifications.monitoring.thresholds', []);
    }

    /**
     * Track notification delivery
     */
    public function trackDelivery(Notification $notification, string $channel, bool $success): void
    {
        // Record delivery metrics
        $this->metrics->increment('notifications.delivery.attempts', 1, [
            'channel' => $channel,
            'status' => $success ? 'success' : 'failure',
            'type' => $notification->type
        ]);

        // Track delivery time
        if ($success) {
            $deliveryTime = $notification->created_at->diffInMilliseconds(now());
            $this->metrics->timing('notifications.delivery.time', $deliveryTime, [
                'channel' => $channel
            ]);

            // Check delivery SLA
            if ($deliveryTime > ($this->thresholds['delivery_time'] ?? 5000)) {
                Log::warning('Notification delivery exceeded SLA', [
                    'notification_id' => $notification->id,
                    'channel' => $channel,
                    'delivery_time' => $deliveryTime
                ]);
            }
        }
    }

    /**
     * Monitor channel health
     */
    public function monitorChannelHealth(string $channel): array
    {
        $window = now()->subHour();
        
        // Calculate success rate
        $attempts = $this->metrics->getCount('notifications.delivery.attempts', [
            'channel' => $channel,
            'time' => ['>=', $window]
        ]);

        $failures = $this->metrics->getCount('notifications.delivery.attempts', [
            'channel' => $channel,
            'status' => 'failure',
            'time' => ['>=', $window]
        ]);

        $successRate = $attempts > 0 ? (($attempts - $failures) / $attempts) * 100 : 100;

        // Get average delivery time
        $averageDeliveryTime = $this->metrics->getAverage('notifications.delivery.time', [
            'channel' => $channel,
            'time' => ['>=', $window]
        ]);

        $health = [
            'status' => $this->determineChannelStatus($successRate, $averageDeliveryTime),
            'success_rate' => round($successRate, 2),
            'average_delivery_time' => round($averageDeliveryTime, 2),
            'total_attempts' => $attempts,
            'failures' => $failures
        ];

        // Cache health status
        Cache::put("notification_channel_health:{$channel}", $health, now()->addMinutes(5));

        return $health;
    }

    /**
     * Track user engagement
     */
    public function trackEngagement(Notification $notification, string $action): void
    {
        $this->metrics->increment('notifications.engagement', 1, [
            'type' => $notification->type,
            'action' => $action
        ]);

        // Track time to engagement
        if ($action === 'read' || $action === 'clicked') {
            $engagementTime = $notification->created_at->diffInSeconds(now());
            $this->metrics->timing('notifications.engagement.time', $engagementTime, [
                'type' => $notification->type,
                'action' => $action
            ]);
        }
    }

    /**
     * Generate performance report
     */
    public function generateReport(array $options = []): array
    {
        $period = $options['period'] ?? 'last_24h';
        $startTime = $this->getReportStartTime($period);

        return [
            'delivery_stats' => $this->getDeliveryStats($startTime),
            'engagement_stats' => $this->getEngagementStats($startTime),
            'channel_health' => $this->getChannelHealthStats(),
            'performance_metrics' => $this->getPerformanceMetrics($startTime)
        ];
    }

    /**
     * Get delivery statistics
     */
    protected function getDeliveryStats(\DateTime $startTime): array
    {
        $stats = [];
        
        foreach (['mail', 'sms', 'push', 'slack'] as $channel) {
            $stats[$channel] = [
                'total' => $this->metrics->getCount('notifications.delivery.attempts', [
                    'channel' => $channel,
                    'time' => ['>=', $startTime]
                ]),
                'success' => $this->metrics->getCount('notifications.delivery.attempts', [
                    'channel' => $channel,
                    'status' => 'success',
                    'time' => ['>=', $startTime]
                ]),
                'average_time' => $this->metrics->getAverage('notifications.delivery.time', [
                    'channel' => $channel,
                    'time' => ['>=', $startTime]
                ])
            ];
        }

        return $stats;
    }

    /**
     * Get engagement statistics
     */
    protected function getEngagementStats(\DateTime $startTime): array
    {
        return [
            'total_sent' => $this->metrics->getCount('notifications.delivery.attempts', [
                'status' => 'success',
                'time' => ['>=', $startTime]
            ]),
            'total_read' => $this->metrics->getCount('notifications.engagement', [
                'action' => 'read',
                'time' => ['>=', $startTime]
            ]),
            'total_clicked' => $this->metrics->getCount('notifications.engagement', [
                'action' => 'clicked',
                'time' => ['>=', $startTime]
            ]),
            'average_time_to_read' => $this->metrics->getAverage('notifications.engagement.time', [
                'action' => 'read',
                'time' => ['>=', $startTime]
            ])
        ];
    }

    /**
     * Get channel health statistics
     */
    protected function getChannelHealthStats(): array
    {
        $stats = [];
        
        foreach (['mail', 'sms', 'push', 'slack'] as $channel) {
            $stats[$channel] = Cache::remember(
                "notification_channel_health:{$channel}",
                now()->addMinutes(5),
                fn() => $this->monitorChannelHealth($channel)
            );
        }

        return $stats;
    }

    /**
     * Get performance metrics
     */
    protected function getPerformanceMetrics(\DateTime $startTime): array
    {
        return [
            'cpu_usage' => $this->performance->getCPUUsage(),
            'memory_usage' => $this->performance->getMemoryUsage(),
            'queue_size' => $this->performance->getQueueSize('notifications'),
            'average_processing_time' => $this->performance->getAverageProcessingTime('notifications')
        ];
    }

    /**
     * Determine channel status based on metrics
     */
    protected function determineChannelStatus(float $successRate, float $averageDeliveryTime): string
    {
        if ($successRate < ($this->thresholds['critical_success_rate'] ?? 90)) {
            return 'critical';
        }

        if ($successRate < ($this->thresholds['warning_success_rate'] ?? 95)) {
            return 'warning';
        }

        if ($averageDeliveryTime > ($this->thresholds['delivery_time'] ?? 5000)) {
            return 'degraded';
        }

        return 'healthy';
    }

    /**
     * Get report start time based on period
     */
    protected function getReportStartTime(string $period): \DateTime
    {
        return match($period) {
            'last_hour' => now()->subHour(),
            'last_24h' => now()->subDay(),
            'last_7d' => now()->subDays(7),
            'last_30d' => now()->subDays(30),
            default => now()->subDay()
        };
    }
}
