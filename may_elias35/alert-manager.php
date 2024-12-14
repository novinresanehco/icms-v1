<?php

namespace App\Core\Audit;

class AlertManager
{
    private NotificationService $notificationService;
    private AlertRepository $repository;
    private ThresholdManager $thresholdManager;
    private RuleEngine $ruleEngine;
    private MetricsCollector $metrics;
    private array $config;

    public function __construct(
        NotificationService $notificationService,
        AlertRepository $repository,
        ThresholdManager $thresholdManager,
        RuleEngine $ruleEngine,
        MetricsCollector $metrics,
        array $config = []
    ) {
        $this->notificationService = $notificationService;
        $this->repository = $repository;
        $this->thresholdManager = $thresholdManager;
        $this->ruleEngine = $ruleEngine;
        $this->metrics = $metrics;
        $this->config = $config;
    }

    public function processEvent(AuditEvent $event): void
    {
        try {
            // Check if event matches any alert rules
            $matchedRules = $this->ruleEngine->evaluateEvent($event);

            if (empty($matchedRules)) {
                return;
            }

            // Process each matched rule
            foreach ($matchedRules as $rule) {
                $this->processRule($event, $rule);
            }

            // Record metrics
            $this->recordAlertMetrics($event, $matchedRules);

        } catch (\Exception $e) {
            $this->handleAlertError($e, $event);
        }
    }

    public function processEventBatch(array $events): void
    {
        $batchAlerts = [];

        foreach ($events as $event) {
            $matchedRules = $this->ruleEngine->evaluateEvent($event);
            
            foreach ($matchedRules as $rule) {
                $batchAlerts[$rule->getId()][] = $event;
            }
        }

        foreach ($batchAlerts as $ruleId => $matchedEvents) {
            $this->processBatchAlert($matchedEvents, $this->ruleEngine->getRule($ruleId));
        }
    }

    protected function processRule(AuditEvent $event, AlertRule $rule): void
    {
        // Check thresholds
        if (!$this->thresholdManager->checkThresholds($event, $rule)) {
            return;
        }

        // Generate alert
        $alert = $this->generateAlert($event, $rule);

        // Store alert
        $this->repository->storeAlert($alert);

        // Send notifications
        $this->sendAlertNotifications($alert);

        // Execute alert actions
        $this->executeAlertActions($alert);
    }

    protected function generateAlert(AuditEvent $event, AlertRule $rule): Alert
    {
        return new Alert([
            'id' => Str::uuid(),
            'rule_id' => $rule->getId(),
            'event_id' => $event->getId(),
            'severity' => $rule->getSeverity(),
            'message' => $this->formatAlertMessage($event, $rule),
            'context' => $this->buildAlertContext($event, $rule),
            'timestamp' => now(),
            'status' => AlertStatus::PENDING
        ]);
    }

    protected function sendAlertNotifications(Alert $alert): void
    {
        $channels = $this->determineNotificationChannels($alert);

        foreach ($channels as $channel) {
            try {
                $this->notificationService->send(
                    $channel,
                    new AlertNotification($alert)
                );
            } catch (\Exception $e) {
                $this->handleNotificationError($e, $alert, $channel);
            }
        }
    }

    protected function executeAlertActions(Alert $alert): void
    {
        $actions = $this->determineAlertActions($alert);

        foreach ($actions as $action) {
            try {
                $result = $action->execute($alert);
                $this->recordActionResult($alert, $action, $result);
            } catch (\Exception $e) {
                $this->handleActionError($e, $alert, $action);
            }
        }
    }

    protected function determineNotificationChannels(Alert $alert): array
    {
        return array_filter(
            $this->config['notification_channels'],
            fn($channel) => $this->shouldNotifyChannel($channel, $alert)
        );
    }

    protected function shouldNotifyChannel(string $channel, Alert $alert): bool
    {
        $channelConfig = $this->config['channels'][$channel] ?? [];
        
        // Check severity threshold
        if (isset($channelConfig['min_severity'])) {
            if ($alert->getSeverity() < $channelConfig['min_severity']) {
                return false;
            }
        }

        // Check time restrictions
        if (isset($channelConfig['quiet_hours'])) {
            if ($this->isInQuietHours($channelConfig['quiet_hours'])) {
                return false;
            }
        }

        // Check rate limiting
        if (isset($channelConfig['rate_limit'])) {
            if ($this->isRateLimited($channel, $channelConfig['rate_limit'])) {
                return false;
            }
        }

        return true;
    }

    protected function recordAlertMetrics(AuditEvent $event, array $matchedRules): void
    {
        $this->metrics->increment('audit_alerts_total', [
            'event_type' => $event->getType(),
            'severity' => $event->getSeverity()
        ]);

        foreach ($matchedRules as $rule) {
            $this->metrics->increment('audit_alerts_by_rule', [
                'rule_id' => $rule->getId(),
                'severity' => $rule->getSeverity()
            ]);
        }
    }

    protected function handleAlertError(\Exception $e, AuditEvent $event): void
    {
        logger()->error('Failed to process audit alert', [
            'event_id' => $event->getId(),
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        $this->metrics->increment('audit_alert_errors', [
            'error_type' => get_class($e)
        ]);

        if ($this->shouldEscalateError($e)) {
            $this->escalateError($e, $event);
        }
    }
}
