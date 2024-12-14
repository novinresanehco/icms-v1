<?php

namespace App\Core\Logging\Monitoring;

class LogMonitor
{
    private LoggerInterface $logger;
    private MetricsCollector $metrics;
    private AlertSystem $alertSystem;
    private DatabaseManager $db;

    public function __construct(
        LoggerInterface $logger,
        MetricsCollector $metrics,
        AlertSystem $alertSystem,
        DatabaseManager $db
    ) {
        $this->logger = $logger;
        $this->metrics = $metrics;
        $this->alertSystem = $alertSystem;
        $this->db = $db;
    }

    public function processLog(LogEntry $entry): void
    {
        try {
            // Record metrics
            $this->recordMetrics($entry);

            // Check for alerts
            $this->checkAlerts($entry);

            // Store in database if needed
            if ($this->shouldStore($entry)) {
                $this->storeLog($entry);
            }

            // Handle critical logs
            if ($this->isCritical($entry)) {
                $this->handleCriticalLog($entry);
            }
        } catch (\Exception $e) {
            // Log monitoring system failure
            $this->logger->emergency('Log monitoring system failure', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    protected function recordMetrics(LogEntry $entry): void
    {
        $this->metrics->increment('logs.processed');
        $this->metrics->increment("logs.level.{$entry->level}");
        
        if (isset($entry->context['performance'])) {
            $this->metrics->record(
                'logs.performance',
                $entry->context['performance']
            );
        }
    }

    protected function checkAlerts(LogEntry $entry): void
    {
        foreach ($this->getAlertRules() as $rule) {
            if ($rule->matches($entry)) {
                $this->alertSystem->trigger(
                    $rule->getAlertType(),
                    $entry
                );
            }
        }
    }

    protected function handleCriticalLog(LogEntry $entry): void
    {
        // Notify on-call team
        $this->alertSystem->notifyOnCall([
            'level' => $entry->level,
            'message' => $entry->message,
            'context' => $entry->context,
            'timestamp' => $entry->timestamp
        ]);

        // Store in separate critical logs collection
        $this->db->table('critical_logs')->insert([
            'entry_id' => $entry->id,
            'level' => $entry->level,
            'message' => $entry->message,
            'context' => json_encode($entry->context),
            'created_at' => $entry->timestamp
        ]);
    }

    protected function shouldStore(LogEntry $entry): bool
    {
        return in_array($entry->level, [
            'emergency',
            'alert',
            'critical',
            'error'
        ]);
    }

    protected function getAlertRules(): array
    {
        return [
            new ErrorFrequencyRule(),
            new CriticalErrorRule(),
            new PerformanceThresholdRule(),
            new SecurityAlertRule()
        ];
    }

    protected function isCritical(LogEntry $entry): bool
    {
        return in_array($entry->level, ['emergency', 'alert', 'critical']);
    }
}
