<?php

namespace App\Core\Monitoring\Notification;

class NotificationMonitor
{
    private NotificationRegistry $registry;
    private DeliveryTracker $deliveryTracker;
    private PerformanceAnalyzer $performanceAnalyzer;
    private AlertManager $alertManager;
    private ChannelMonitor $channelMonitor;

    public function monitor(): NotificationStatus
    {
        $channelStatus = [];
        $deliveryMetrics = $this->deliveryTracker->getMetrics();
        $performanceMetrics = $this->performanceAnalyzer->analyze();

        foreach ($this->registry->getChannels() as $channel) {
            $status = $this->channelMonitor->monitor($channel);
            if ($status->hasIssues()) {
                $this->alertManager->notifyChannelIssue($status);
            }
            $channelStatus[$channel->getName()] = $status;
        }

        return new NotificationStatus($channelStatus, $deliveryMetrics, $performanceMetrics);
    }
}

class ChannelMonitor
{
    private HealthChecker $healthChecker;
    private DeliveryAnalyzer $deliveryAnalyzer;
    private RateLimitMonitor $rateLimitMonitor;

    public function monitor(NotificationChannel $channel): ChannelStatus
    {
        $health = $this->healthChecker->check($channel);
        $delivery = $this->deliveryAnalyzer->analyze($channel);
        $rateLimit = $this->rateLimitMonitor->check($channel);

        return new ChannelStatus($channel, $health, $delivery, $rateLimit);
    }
}

class DeliveryTracker
{
    private MetricsStorage $storage;
    private DeliveryAnalyzer $analyzer;
    private TimeWindow $window;

    public function trackDelivery(Notification $notification, DeliveryResult $result): void
    {
        $metrics = [
            'notification_id' => $notification->getId(),
            'channel' => $notification->getChannel(),
            'status' => $result->getStatus(),
            'delivery_time' => $result->getDeliveryTime(),
            'attempts' => $result->getAttempts(),
            'timestamp' => microtime(true)
        ];

        $this->storage->store($metrics);
        $this->analyzer->analyze($metrics);
    }

    public function getMetrics(): DeliveryMetrics
    {
        return new DeliveryMetrics(
            $this->storage->getMetrics($this->window),
            $this->analyzer->getAggregates($this->window)
        );
    }
}

class PerformanceAnalyzer
{
    private ThresholdManager $thresholds;
    private MetricsCollector $collector;
    private TrendAnalyzer $trendAnalyzer;

    public function analyze(): PerformanceMetrics
    {
        $metrics = $this->collector->collect();
        $trends = $this->trendAnalyzer->analyze($metrics);
        $violations = $this->thresholds->check($metrics);

        return new PerformanceMetrics($metrics, $trends, $violations);
    }
}

class NotificationStatus
{
    private array $channelStatus;
    private DeliveryMetrics $deliveryMetrics;
    private PerformanceMetrics $performanceMetrics;
    private float $timestamp;

    public function __construct(
        array $channelStatus,
        DeliveryMetrics $deliveryMetrics,
        PerformanceMetrics $performanceMetrics
    ) {
        $this->channelStatus = $channelStatus;
        $this->deliveryMetrics = $deliveryMetrics;
        $this->performanceMetrics = $performanceMetrics;
        $this->timestamp = microtime(true);
    }

    public function hasIssues(): bool
    {
        foreach ($this->channelStatus as $status) {
            if ($status->hasIssues()) {
                return true;
            }
        }

        return $this->performanceMetrics->hasIssues();
    }

    public function getChannelStatus(string $channel): ?ChannelStatus
    {
        return $this->channelStatus[$channel] ?? null;
    }

    public function getDeliveryMetrics(): DeliveryMetrics
    {
        return $this->deliveryMetrics;
    }

    public function getPerformanceMetrics(): PerformanceMetrics
    {
        return $this->performanceMetrics;
    }
}

class ChannelStatus
{
    private NotificationChannel $channel;
    private HealthStatus $health;
    private DeliveryAnalysis $delivery;
    private RateLimitStatus $rateLimit;

    public function __construct(
        NotificationChannel $channel,
        HealthStatus $health,
        DeliveryAnalysis $delivery,
        RateLimitStatus $rateLimit
    ) {
        $this->channel = $channel;
        $this->health = $health;
        $this->delivery = $delivery;
        $this->rateLimit = $rateLimit;
    }

    public function hasIssues(): bool
    {
        return $this->health->hasIssues() ||
               $this->delivery->hasIssues() ||
               $this->rateLimit->isLimited();
    }

    public function getChannel(): NotificationChannel
    {
        return $this->channel;
    }

    public function getHealth(): HealthStatus
    {
        return $this->health;
    }

    public function getDelivery(): DeliveryAnalysis
    {
        return $this->delivery;
    }

    public function getRateLimit(): RateLimitStatus
    {
        return $this->rateLimit;
    }
}

class DeliveryMetrics
{
    private array $metrics;
    private array $aggregates;
    private float $timestamp;

    public function __construct(array $metrics, array $aggregates)
    {
        $this->metrics = $metrics;
        $this->aggregates = $aggregates;
        $this->timestamp = microtime(true);
    }

    public function getMetrics(): array
    {
        return $this->metrics;
    }

    public function getAggregates(): array
    {
        return $this->aggregates;
    }

    public function getTimestamp(): float
    {
        return $this->timestamp;
    }
}
