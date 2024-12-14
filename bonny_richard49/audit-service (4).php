<?php

namespace App\Core\Security;

final class AuditService
{
    private LogManager $logger;
    private AlertService $alerts;
    private MetricsCollector $metrics;
    private StorageService $storage;

    public function __construct(
        LogManager $logger,
        AlertService $alerts,
        MetricsCollector $metrics,
        StorageService $storage
    ) {
        $this->logger = $logger;
        $this->alerts = $alerts;
        $this->metrics = $metrics;
        $this->storage = $storage;
    }

    public function startAudit(SecurityContext $context): string
    {
        $auditId = uniqid('audit_', true);

        // Log audit start
        $this->logger->info('Security audit started', [
            'audit_id' => $auditId,
            'context' => $context->toArray(),
            'timestamp' => microtime(true)
        ]);

        // Initialize metrics
        $this->metrics->initializeAudit($auditId);

        return $auditId;
    }

    public function recordSuccess(string $auditId): void
    {
        // Collect final metrics
        $metrics = $this->metrics->collectAuditMetrics($auditId);

        // Store audit record
        $this->storage->storeAuditRecord([
            'audit_id' => $auditId,
            'status' => 'success',
            'metrics' => $metrics,
            'timestamp' => microtime(true)
        ]);

        // Log success
        $this->logger->info('Security audit completed successfully', [
            'audit_id' => $auditId,
            'metrics' => $metrics
        ]);
    }

    public function recordFailure(string $auditId, \Throwable $e): void
    {
        // Collect failure metrics
        $metrics = $this->metrics->collectAuditMetrics($auditId);

        // Generate comprehensive failure record
        $record = [
            'audit_id' => $auditId,
            'status' => 'failure',
            'error' => [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'trace' => $e->getTraceAsString()
            ],
            'metrics' => $metrics,
            'timestamp' => microtime(true)
        ];

        // Store failure record
        $this->storage->storeAuditRecord($record);

        // Log failure
        $this->logger->error('Security audit failed', $record);

        // Trigger alerts
        $this->alerts->triggerSecurityAlert($record);
    }

    public function logDataAccess(ProtectedData $data, array $context): void
    {
        $this->logger->info('Protected data accessed', [
            'data_id' => $data->getId(),
            'context' => $context,
            'timestamp' => microtime(true)
        ]);
    }

    public function logDataProtection(ProtectedData $data): void
    {
        $this->logger->info('Data protection applied', [
            'data_id' => $data->getId(),
            'protection_level' => $data->getProtectionLevel(),
            'timestamp' => microtime(true)
        ]);
    }

    public function logAuthFailure(SecurityContext $context): void
    {
        $record = [
            'context' => $context->toArray(),
            'timestamp' => microtime(true),
            'ip_address' => $context->getIpAddress(),
            'user_id' => $context->getUserId()
        ];

        $this->logger->warning('Authentication failure', $record);
        $this->alerts->handleAuthFailure($record);
    }
}
