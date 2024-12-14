namespace App\Core\Security;

class AuditLogger implements AuditLoggerInterface
{
    private LogManager $logger;
    private SecurityConfig $config;
    private MetricsCollector $metrics;
    private StorageManager $storage;
    private EncryptionService $encryption;

    public function __construct(
        LogManager $logger,
        SecurityConfig $config,
        MetricsCollector $metrics,
        StorageManager $storage,
        EncryptionService $encryption
    ) {
        $this->logger = $logger;
        $this->config = $config;
        $this->metrics = $metrics;
        $this->storage = $storage;
        $this->encryption = $encryption;
    }

    public function logCriticalOperation(string $operation, array $data, SecurityContext $context): void 
    {
        DB::beginTransaction();
        try {
            $logEntry = $this->createLogEntry($operation, $data, $context);
            
            // Store encrypted log
            $this->storeSecureLog($logEntry);
            
            // Real-time alerts for critical operations
            $this->processRealTimeAlerts($logEntry);
            
            // Update metrics
            $this->updateMetrics($operation, $logEntry);
            
            // Archive if needed
            if ($this->requiresArchival($operation)) {
                $this->archiveLogEntry($logEntry);
            }
            
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleLoggingFailure($e, $operation, $data);
        }
    }

    public function logSecurityEvent(SecurityEvent $event): void 
    {
        $startTime = microtime(true);
        try {
            $eventData = [
                'type' => $event->getType(),
                'severity' => $event->getSeverity(),
                'source' => $event->getSource(),
                'context' => $this->encryption->encrypt($event->getContext()),
                'timestamp' => now(),
                'hash' => $this->generateEventHash($event)
            ];

            // Store event
            $this->storage->store('security_events', $eventData);

            // Process alerts
            if ($event->isCritical()) {
                $this->processCriticalEventAlert($event);
            }

            // Update security metrics
            $this->metrics->recordSecurityEvent(
                $event->getType(),
                microtime(true) - $startTime
            );

        } catch (\Exception $e) {
            $this->handleSecurityEventFailure($e, $event);
        }
    }

    public function logAccessAttempt(AccessAttempt $attempt): void 
    {
        try {
            $attemptData = [
                'user_id' => $attempt->getUserId(),
                'resource' => $attempt->getResource(),
                'action' => $attempt->getAction(),
                'ip_address' => $attempt->getIpAddress(),
                'user_agent' => $attempt->getUserAgent(),
                'success' => $attempt->isSuccessful(),
                'timestamp' => now(),
                'metadata' => $this->encryption->encrypt($attempt->getMetadata())
            ];

            // Store attempt
            $this->storage->store('access_attempts', $attemptData);

            // Update access metrics
            $this->metrics->recordAccessAttempt(
                $attempt->getAction(),
                $attempt->isSuccessful()
            );

            // Check for suspicious patterns
            $this->detectSuspiciousActivity($attempt);

        } catch (\Exception $e) {
            $this->handleAccessLoggingFailure($e, $attempt);
        }
    }

    public function logSystemError(\Throwable $error, array $context = []): void 
    {
        try {
            $errorData = [
                'message' => $error->getMessage(),
                'code' => $error->getCode(),
                'file' => $error->getFile(),
                'line' => $error->getLine(),
                'trace' => $this->encryption->encrypt($error->getTraceAsString()),
                'context' => $this->encryption->encrypt($context),
                'severity' => $this->calculateErrorSeverity($error),
                'timestamp' => now()
            ];

            // Store error
            $this->storage->store('system_errors', $errorData);

            // Process critical errors
            if ($this->isCriticalError($error)) {
                $this->processCriticalError($error, $context);
            }

            // Update error metrics
            $this->metrics->recordSystemError(
                get_class($error),
                $this->calculateErrorSeverity($error)
            );

        } catch (\Exception $e) {
            $this->handleErrorLoggingFailure($e, $error);
        }
    }

    protected function createLogEntry(string $operation, array $data, SecurityContext $context): array
    {
        return [
            'id' => Str::uuid(),
            'operation' => $operation,
            'data' => $this->encryption->encrypt($data),
            'user_id' => $context->getUserId(),
            'ip_address' => $context->getIpAddress(),
            'user_agent' => $context->getUserAgent(),
            'timestamp' => now(),
            'hash' => $this->generateLogHash($operation, $data, $context)
        ];
    }

    protected function storeSecureLog(array $logEntry): void
    {
        $this->storage->store('audit_logs', $logEntry);
        
        if ($this->config->get('audit.redundant_storage')) {
            $this->storeRedundantLog($logEntry);
        }
    }

    protected function generateLogHash(string $operation, array $data, SecurityContext $context): string
    {
        return hash_hmac(
            'sha256',
            json_encode([$operation, $data, $context->toArray()]),
            $this->config->get('audit.hash_key')
        );
    }

    protected function handleLoggingFailure(\Exception $e, string $operation, array $data): void
    {
        $this->logger->critical('Audit logging failed', [
            'operation' => $operation,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        if ($this->config->get('audit.throw_on_failure')) {
            throw new AuditLoggingException(
                'Failed to log audit entry: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }
}
