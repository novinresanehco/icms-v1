<?php

namespace App\Core\Audit;

use App\Core\Interfaces\{
    AuditLoggerInterface,
    SecurityContextInterface
};

class AuditLogger implements AuditLoggerInterface
{
    private LogProcessor $processor;
    private LogStore $store;
    private MonitoringService $monitor;
    private string $environment;

    public function __construct(
        LogProcessor $processor,
        LogStore $store,
        MonitoringService $monitor,
        string $environment
    ) {
        $this->processor = $processor;
        $this->store = $store;
        $this->monitor = $monitor;
        $this->environment = $environment;
    }

    public function startOperation(array $context): string
    {
        $operationId = $this->generateOperationId();
        
        $this->store->store([
            'type' => 'operation_start',
            'operation_id' => $operationId,
            'timestamp' => time(),
            'environment' => $this->environment,
            'context' => $this->processor->processContext($context)
        ]);

        $this->monitor->trackOperation($operationId, $context);

        return $operationId;
    }

    public function logSuccess(array $data): void
    {
        $logEntry = $this->processor->processSuccessLog($data);
        
        $this->store->store([
            'type' => 'success',
            'timestamp' => time(),
            'environment' => $this->environment,
            'data' => $logEntry
        ]);

        $this->monitor->recordSuccess($data);
    }

    public function logFailure(array $data): void
    {
        $logEntry = $this->processor->processFailureLog($data);

        $this->store->store([
            'type' => 'failure',
            'severity' => 'ERROR',
            'timestamp' => time(), 
            'environment' => $this->environment,
            'data' => $logEntry
        ]);

        $this->monitor->recordFailure($data);
        $this->escalateIfNeeded($data);
    }

    public function logSecurityEvent(array $data): void
    {
        $logEntry = $this->processor->processSecurityLog($data);

        $this->store->store([
            'type' => 'security',
            'severity' => 'CRITICAL',
            'timestamp' => time(),
            'environment' => $this->environment,
            'data' => $logEntry
        ]);

        $this->monitor->recordSecurityEvent($data);
        $this->escalateSecurityEvent($data);
    }

    protected function generateOperationId(): string
    {
        return uniqid('op_', true);
    }

    protected function escalateIfNeeded(array $data): void
    {
        if ($this->shouldEscalate($data)) {
            $this->monitor->triggerAlert([
                'type' => 'failure_escalation',
                'severity' => 'HIGH',
                'data' => $data
            ]);
        }
    }

    protected function escalateSecurityEvent(array $data): void
    {
        $this->monitor->triggerAlert([
            'type' => 'security_escalation',
            'severity' => 'CRITICAL',
            'data' => $data
        ]);
    }

    protected function shouldEscalate(array $data): bool
    {
        return isset($data['severity']) && 
               in_array($data['severity'], ['ERROR', 'CRITICAL']);
    }
}
