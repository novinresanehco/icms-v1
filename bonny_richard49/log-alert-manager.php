<?php

namespace App\Core\Logging\Alerts;

class LogAlertManager implements AlertManagerInterface
{
    private NotificationDispatcher $dispatcher;
    private AlertConfigRepository $configRepository;
    private MetricsCollector $metrics;
    private ThresholdManager $thresholds;

    public function __construct(
        NotificationDispatcher $dispatcher,
        AlertConfigRepository $configRepository,
        MetricsCollector $metrics,
        ThresholdManager $thresholds
    ) {
        $this->dispatcher = $dispatcher;
        $this->configRepository = $configRepository;
        $this->metrics = $metrics;
        $this->thresholds = $thresholds;
    }

    public function handleLogEntry(LogEntry $entry): void
    {
        try {
            // Check alert conditions
            $alerts = $this->checkAlertConditions($entry);

            // Process triggered alerts
            foreach ($alerts as $alert) {
                $this->processAlert($alert, $entry);
            }

            // Update metrics
            $this->updateAlertMetrics($alerts);

        } catch (\Exception $e) {
            // Handle alert processing failure
            $this->handleAlertFailure($entry, $e);
        }
    }

    protected function checkAlertConditions(LogEntry $entry): array
    {
        $triggeredAlerts = [];
        $conditions = $this->configRepository->getActiveConditions();

        foreach ($conditions as $condition) {
            if ($this->evaluateCondition($condition, $entry)) {
                $triggeredAlerts[] = new Alert([
                    'condition' => $condition,
                    'entry' => $entry,
                    'timestamp' => now(),
                    'severity' => $this->calculateSeverity($condition, $entry)
                ]);
            }
        }

        return $triggeredAlerts;
    }

    protected function processAlert(Alert $alert, LogEntry $entry): void
    {
        // Check threshold limits
        if (!$this->thresholds->canSendAlert($alert)) {
            $this->metrics->increment('alerts.throttled');
            return;
        }

        // Prepare notification data
        $notification = $this->prepareNotification($alert, $entry);

        // Dispatch to appropriate channels
        $this->dispatcher->dispatch($notification, $this->getAlertChannels($alert));

        // Record alert
        $this->recordAlert($alert);
    }

    protected function prepareNotification(Alert $alert, LogEntry $entry): AlertNotification
    {
        return new AlertNotification([
            'title' => $this->generateAlertTitle($alert),
            'message' => $this->generateAlertMessage($alert, $entry),
            'severity' => $alert->severity,
            'metadata' => [
                'alert_id' => $alert->id,
                'condition_id' => $alert->condition->id,
                'log_entry_id' => $entry->id,
                'triggered_at' => $alert->timestamp,
                'environment' => config('app.env')
            ],
            'actions' => $this->getAlertActions($alert)
        ]);
    }

    protected function calculateSeverity(AlertCondition $condition, LogEntry $entry): string
    {
        $severity = $condition->getBaseSeverity();

        // Adjust based on log level
        if (in_array($entry->level, ['emergency', 'alert', 'critical'])) {
            $severity = max($severity, 'critical');
        }

        // Adjust based on frequency
        $frequency = $this->metrics->getAlertFrequency($condition->id);
        if ($frequency > $condition->getHighFrequencyThreshold()) {
            $severity = max($severity, 'high');
        }

        return $severity;
    }

    protected function getAlertChannels(Alert $alert): array
    {
        $channels = $alert->condition->getChannels();

        // Add additional channels based on severity
        if ($alert->severity === 'critical') {
            $channels = array_merge($channels, ['sms', 'phone']);
        }

        return array_unique($channels);
    }

    protected function recordAlert(Alert $alert): void
    {
        DB::transaction(function () use ($alert) {
            // Record in database
            $this->configRepository->recordAlert($alert);

            // Update metrics
            $this->metrics->increment('alerts.sent');
            $this->metrics->increment("alerts.severity.{$alert->severity}");

            // Update thresholds
            $this->thresholds->recordAlert($alert);
        });
    }

    protected function handleAlertFailure(LogEntry $entry, \Exception $e): void
    {
        // Log the failure
        Log::error('Alert processing failed', [
            'error' => $e->getMessage(),
            'log_entry_id' => $entry->id,
            'trace' => $e->getTraceAsString()
        ]);

        // Record metric
        $this->metrics->increment('alerts.failures');

        // Notify admin if critical
        if ($entry->level === 'critical') {
            $this->notifyAdminOfFailure($entry, $e);
        }
    }

    protected function notifyAdminOfFailure(LogEntry $entry, \Exception $e): void
    {
        $this->dispatcher->dispatch(
            new AdminNotification(
                'Alert System Failure',
                [
                    'error' => $e->getMessage(),
                    'log_entry' => $entry->toArray(),
                    'timestamp' => now()
                ]
            ),
            ['admin_channel']
        );
    }
}
