namespace App\Core\Audit;

class AuditLogger
{
    private LogHandler $handler;
    private SecurityConfig $config;
    private MetricsCollector $metrics;

    public function logSecurityEvent(SecurityEvent $event): void
    {
        $this->log('security', [
            'type' => $event->getType(),
            'severity' => $event->getSeverity(),
            'details' => $event->getDetails(),
            'timestamp' => time()
        ]);

        if ($event->isCritical()) {
            $this->notifySecurityTeam($event);
        }

        $this->metrics->incrementCounter(
            "security_events_total",
            ["type" => $event->getType()]
        );
    }

    public function logAccessAttempt(AccessAttempt $attempt): void
    {
        $this->log('access', [
            'user' => $attempt->getUserId(),
            'resource' => $attempt->getResource(),
            'action' => $attempt->getAction(),
            'success' => $attempt->isSuccessful(),
            'ip' => $attempt->getIpAddress(),
            'timestamp' => time()
        ]);

        if (!$attempt->isSuccessful()) {
            $this->metrics->incrementCounter(
                "failed_access_attempts_total",
                ["resource" => $attempt->getResource()]
            );
        }
    }

    public function logOperationResult(OperationResult $result): void
    {
        $this->log('operation', [
            'type' => $result->getType(),
            'success' => $result->isSuccessful(),
            'duration' => $result->getDuration(),
            'timestamp' => time()
        ]);

        $this->metrics->observeHistogram(
            "operation_duration_seconds",
            $result->getDuration(),
            ["type" => $result->getType()]
        );
    }

    private function log(string $type, array $data): void
    {
        $entry = [
            'type' => $type,
            'environment' => $this->config->getEnvironment(),
            'data' => $data,
            'metadata' => [
                'host' => gethostname(),
                'pid' => getmypid(),
                'memory' => memory_get_usage(true)
            ]
        ];

        $this->handler->write($entry);
    }

    private function notifySecurityTeam(SecurityEvent $event): void
    {
        // Critical security team notification
    }
}

class SecurityEvent
{
    private string $type;
    private string $severity;
    private array $details;
    private \DateTimeImmutable $timestamp;

    public function __construct(string $type, string $severity, array $details)
    {
        $this->type = $type;
        $this->severity = $severity;
        $this->details = $details;
        $this->timestamp = new \DateTimeImmutable();
    }

    public function isCritical(): bool
    {
        return $this->severity === 'critical';
    }

    public function getType(): string 
    {
        return $this->type;
    }

    public function getSeverity(): string
    {
        return $this->severity;
    }

    public function getDetails(): array
    {
        return $this->details;
    }
}
