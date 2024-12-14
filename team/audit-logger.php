namespace App\Core\Audit;

class AuditLogger implements AuditInterface 
{
    private LogManager $logger;
    private MetricsCollector $metrics;
    private StorageManager $storage;
    private EncryptionService $encryption;
    private AuditConfig $config;
    private QueueManager $queue;

    public function __construct(
        LogManager $logger,
        MetricsCollector $metrics,
        StorageManager $storage,
        EncryptionService $encryption,
        AuditConfig $config,
        QueueManager $queue
    ) {
        $this->logger = $logger;
        $this->metrics = $metrics;
        $this->storage = $storage;
        $this->encryption = $encryption;
        $this->config = $config;
        $this->queue = $queue;
    }

    public function logSecurityEvent(
        string $event,
        SecurityContext $context,
        array $data = []
    ): void {
        $entry = $this->createAuditEntry(
            AuditType::SECURITY,
            $event,
            $context,
            $data
        );

        $this->processAuditEntry($entry, true);
    }

    public function logOperationEvent(
        string $operation,
        SecurityContext $context,
        array $data = []
    ): void {
        $entry = $this->createAuditEntry(
            AuditType::OPERATION,
            $operation,
            $context,
            $data
        );

        $this->processAuditEntry($entry);
    }

    public function logSystemEvent(
        string $event,
        SecurityContext $context,
        array $data = []
    ): void {
        $entry = $this->createAuditEntry(
            AuditType::SYSTEM,
            $event,
            $context,
            $data
        );

        $this->processAuditEntry($entry);
    }

    private function createAuditEntry(
        string $type,
        string $event,
        SecurityContext $context,
        array $data
    ): AuditEntry {
        return new AuditEntry([
            'type' => $type,
            'event' => $event,
            'user_id' => $context->getUser()->getId(),
            'ip_address' => $context->getIpAddress(),
            'timestamp' => microtime(true),
            'data' => $this->sanitizeData($data),
            'session_id' => $context->getSessionId(),
            'trace_id' => $context->getTraceId(),
            'severity' => $this->determineSeverity($type, $event),
            'environment' => $this->config->getEnvironment(),
            'system_state' => $this->captureSystemState()
        ]);
    }

    private function processAuditEntry(AuditEntry $entry, bool $critical = false): void 
    {
        try {
            if ($critical) {
                $this->processCriticalEntry($entry);
            } else {
                $this->processStandardEntry($entry);
            }

            $this->metrics->incrementAuditCount($entry->type);

        } catch (\Exception $e) {
            $this->handleAuditFailure($e, $entry);
        }
    }

    private function processCriticalEntry(AuditEntry $entry): void 
    {
        $encryptedEntry = $this->encryption->encrypt(
            $entry->toJson(),
            $this->config->getEncryptionKey()
        );

        $this->storage->storeCriticalAudit($encryptedEntry);
        $this->logger->critical($entry->event, $entry->toArray());
        
        if ($this->config->hasAlertingEnabled()) {
            $this->queue->dispatch(
                new SecurityAlertJob($entry),
                QueuePriority::HIGH
            );
        }
    }

    private function processStandardEntry(AuditEntry $entry): void 
    {
        $this->queue->dispatch(
            new AuditStorageJob($entry),
            QueuePriority::MEDIUM
        );

        $this->logger->info($entry->event, $entry->toArray());
    }

    private function sanitizeData(array $data): array 
    {
        return array_map(function ($value) {
            if ($this->isSensitive($value)) {
                return '[REDACTED]';
            }
            if (is_array($value)) {
                return $this->sanitizeData($value);
            }
            return $value;
        }, $data);
    }

    private function isSensitive($value): bool 
    {
        foreach ($this->config->getSensitivePatterns() as $pattern) {
            if (preg_match($pattern, (string)$value)) {
                return true;
            }
        }
        return false;
    }

    private function determineSeverity(string $type, string $event): string 
    {
        foreach ($this->config->getSeverityRules() as $rule) {
            if ($rule->matches($type, $event)) {
                return $rule->getSeverity();
            }
        }
        return AuditSeverity::INFO;
    }

    private function captureSystemState(): array 
    {
        return [
            'memory_usage' => memory_get_usage(true),
            'cpu_load' => sys_getloadavg(),
            'disk_space' => disk_free_space('/'),
            'php_version' => PHP_VERSION,
            'timestamp' => microtime(true)
        ];
    }

    private function handleAuditFailure(\Exception $e, AuditEntry $entry): void 
    {
        $this->logger->emergency('Audit logging failed', [
            'exception' => $e->getMessage(),
            'entry' => $entry->toArray(),
            'trace' => $e->getTraceAsString()
        ]);

        $this->metrics->incrementFailureCount('audit_logging');

        if ($this->config->hasFailoverEnabled()) {
            $this->queue->dispatch(
                new AuditFailoverJob($entry),
                QueuePriority::HIGH
            );
        }
    }
}
