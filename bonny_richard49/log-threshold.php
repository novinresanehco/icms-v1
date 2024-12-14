<?php

namespace App\Core\Logging\Thresholds;

class ThresholdManager implements ThresholdManagerInterface
{
    private CacheManager $cache;
    private MetricsCollector $metrics;
    private Config $config;

    public function __construct(
        CacheManager $cache,
        MetricsCollector $metrics,
        Config $config
    ) {
        $this->cache = $cache;
        $this->metrics = $metrics;
        $this->config = $config;
    }

    public function canSendAlert(Alert $alert): bool
    {
        // Check global rate limit
        if (!$this->checkGlobalThreshold()) {
            return false;
        }

        // Check condition-specific threshold
        if (!$this->checkConditionThreshold($alert->condition)) {
            return false;
        }

        // Check severity-based threshold
        if (!$this->checkSeverityThreshold($alert->severity)) {
            return false;
        }

        return true;
    }

    public function recordAlert(Alert $alert): void
    {
        $this->incrementCounters($alert);
        $this->updateMetrics($alert);
        $this->checkThresholdViolations($alert);
    }

    protected function checkGlobalThreshold(): bool
    {
        $key = 'alert_thresholds:global';
        $limit = $this->config->get('alerts.global_limit_per_minute', 60);
        
        return $this->cache->remember($key, 60, function () use ($limit) {
            $count = $this->metrics->getCountLastMinute('alerts.sent');
            return $count < $limit;
        });
    }

    protected function checkConditionThreshold(AlertCondition $condition): bool
    {
        $key = "alert_thresholds:condition:{$condition->id}";
        $limit = $condition->getThresholdLimit();
        $window = $condition->getThresholdWindow();

        return $this->cache->remember($key, $window, function () use ($condition, $limit) {
            $count = $this->metrics->getConditionAlertCount(
                $condition->id,
                now()->subSeconds($window)
            );
            return $count < $limit;
        });
    }

    protected function checkSeverityThreshold(string $severity): bool
    {
        $key = "alert_thresholds:severity:{$severity}";
        $limit = $this->config->get("alerts.severity_limits.{$severity}", 30);

        return $this->cache->remember($key, 60, function () use ($severity, $limit) {
            $count = $this->metrics->getCountLastMinute("alerts.severity.{$severity}");
            return $count < $limit;
        });
    }

    protected function incrementCounters(Alert $alert): void
    {
        $now = now()->timestamp;

        Pipeline::create()
            ->send([
                'alert' => $alert,
                'timestamp' => $now
            ])
            ->through([
                GlobalCounterIncrement::class,
                ConditionCounterIncrement::class,
                SeverityCounterIncrement::class,
                ChannelCounterIncrement::class
            ])
            ->then(function ($data) {
                $this->cache->tags(['alert_counters'])->flush();
            });
    }

    protected function updateMetrics(Alert $alert): void
    {
        // Update general metrics
        $this->metrics->increment('alerts.sent');
        $this->metrics->increment("alerts.severity.{$alert->severity}");
        
        // Update condition-specific metrics
        $this->metrics->increment(
            "alerts.condition.{$alert->condition->id}"
        );

        // Record timing metrics
        $this->metrics->timing(
            'alerts.processing_time',
            $alert->getProcessingTime()
        );
    }

    protected function checkThresholdViolations(Alert $alert): void
    {
        $violations = [];

        // Check various thresholds
        if ($this->isGlobalThresholdViolated()) {
            $violations[] = 'global_rate_limit_exceeded';
        }

        if ($this->isConditionThresholdViolated($alert->condition)) {
            $violations[] = 'condition_threshold_exceeded';
        }

        if ($this->isSeverityThreshol