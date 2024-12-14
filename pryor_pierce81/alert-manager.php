<?php

namespace App\Core\Monitoring\UserActivity;

class AlertManager
{
    private NotificationService $notificationService;
    private AlertRepository $repository;
    private AlertFormatter $formatter;
    private array $handlers;
    
    public function __construct(
        NotificationService $notificationService,
        AlertRepository $repository,
        AlertFormatter $formatter,
        array $handlers = []
    ) {
        $this->notificationService = $notificationService;
        $this->repository = $repository;
        $this->formatter = $formatter;
        $this->handlers = $handlers;
    }

    public function notify(ActivityAlert $alert): void
    {
        try {
            // Store alert
            $this->repository->save($alert);

            // Format alert for different channels
            $formatted = $this->formatter->format($alert);

            // Process through handlers
            foreach ($this->handlers as $handler) {
                if ($handler->shouldHandle($alert)) {
                    $handler->handle($alert);
                }
            }

            // Send notifications
            $this->notificationService->send($formatted);
        } catch (\Exception $e) {
            // Log error but don't throw to avoid disrupting monitoring
            error_log("Alert notification failed: " . $e->getMessage());
        }
    }
}

class ActivityAlert
{
    private ActivityStatus $status;
    private string $severity;
    private float $timestamp;
    private array $metadata;

    public function __construct(
        ActivityStatus $status,
        string $severity = 'medium',
        array $metadata = []
    ) {
        $this->status = $status;
        $this->severity = $severity;
        $this->timestamp = microtime(true);
        $this->metadata = $metadata;
    }

    public function getStatus(): ActivityStatus
    {
        return $this->status;
    }

    public function getSeverity(): string
    {
        return $this->severity;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }
}

class AlertRepository
{
    private \PDO $db;
    private string $table;

    public function save(ActivityAlert $alert): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO {$this->table} 
            (severity, timestamp, metadata, status_data)
            VALUES (:severity, :timestamp, :metadata, :status_data)
        ");

        $stmt->execute([
            'severity' => $alert->getSeverity(),
            'timestamp' => $alert->getTimestamp(),
            'metadata' => json_encode($alert->getMetadata()),
            'status_data' => json_encode($alert->getStatus())
        ]);
    }

    public function findRecentAlerts(int $minutes = 60): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM {$this->table}
            WHERE timestamp >= :since
            ORDER BY timestamp DESC
        ");

        $since = time() - ($minutes * 60);
        $stmt->execute(['since' => $since]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}

class AlertFormatter
{
    private array $formatters;
    
    public function format(ActivityAlert $alert): FormattedAlert
    {
        $formatted = [];
        foreach ($this->formatters as $type => $formatter) {
            $formatted[$type] = $formatter->format($alert);
        }

        return new FormattedAlert($alert, $formatted);
    }
}

class FormattedAlert
{
    private ActivityAlert $alert;
    private array $formats;

    public function __construct(ActivityAlert $alert, array $formats)
    {
        $this->alert = $alert;
        $this->formats = $formats;
    }

    public function getFormat(string $type): ?string
    {
        return $this->formats[$type] ?? null;
    }

    public function getAlert(): ActivityAlert
    {
        return $this->alert;
    }
}

interface AlertHandler
{
    public function shouldHandle(ActivityAlert $alert): bool;
    public function handle(ActivityAlert $alert): void;
}

class CriticalAlertHandler implements AlertHandler
{
    private EmergencyNotifier $emergencyNotifier;
    private IncidentTracker $incidentTracker;

    public function shouldHandle(ActivityAlert $alert): bool
    {
        return $alert->getSeverity() === 'critical';
    }

    public function handle(ActivityAlert $alert): void
    {
        $this->emergencyNotifier->notifyTeam($alert);
        $this->incidentTracker->createIncident($alert);
    }
}

class ComplianceAlertHandler implements AlertHandler
{
    private ComplianceLogger $logger;
    private ReportGenerator $reportGenerator;

    public function shouldHandle(ActivityAlert $alert): bool
    {
        $security = $alert->getStatus()->getSecurity();
        return $security->hasComplianceIssues();
    }

    public function handle(ActivityAlert $alert): void
    {
        $this->logger->logComplianceIssue($alert);
        $this->reportGenerator->generateComplianceReport($alert);
    }
}
