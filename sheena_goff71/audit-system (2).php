<?php

namespace App\Core\Audit;

use Illuminate\Support\Facades\DB;
use App\Core\Contracts\AuditInterface;
use App\Core\Security\SecurityContext;

class AuditSystem implements AuditInterface 
{
    private MetricsCollector $metrics;
    private LogManager $logger;
    private SecurityConfig $config;
    private AlertSystem $alerts;

    public function __construct(
        MetricsCollector $metrics,
        LogManager $logger,
        SecurityConfig $config,
        AlertSystem $alerts
    ) {
        $this->metrics = $metrics;
        $this->logger = $logger;
        $this->config = $config;
        $this->alerts = $alerts;
    }

    public function logSecurityEvent(SecurityContext $context, string $event, array $data = []): void 
    {
        $startTime = microtime(true);
        DB::beginTransaction();

        try {
            $eventData = $this->prepareEventData($context, $event, $data);
            
            $this->validateEventData($eventData);
            $this->storeSecurityEvent($eventData);
            $this->processEventTriggers($eventData);
            
            if ($this->isHighRiskEvent($event)) {
                $this->handleHighRiskEvent($eventData);
            }

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleAuditFailure($e, $eventData);
            throw new AuditException('Failed to log security event', 0, $e);
        } finally {
            $this->recordMetrics(__FUNCTION__, microtime(true) - $startTime);
        }
    }

    public function logAccessAttempt(SecurityContext $context, bool $success): void 
    {
        $data = [
            'user_id' => $context->getUser()?->id,
            'ip_address' => $context->getIpAddress(),
            'user_agent' => $context->getUserAgent(),
            'resource' => $context->getResource(),
            'action' => $context->getAction(),
            'success' => $success,
            'timestamp' => now(),
            'session_id' => $context->getSessionId()
        ];

        $this->storeAccessLog($data);
        
        if (!$success) {
            $this->handleFailedAccess($context);
        }

        $this->metrics->incrementCounter(
            "access_attempt",
            ['success' => $success]
        );
    }

    public function logCriticalOperation(CriticalOperation $operation, OperationResult $result): void 
    {
        DB::beginTransaction();

        try {
            $logData = [
                'operation_type' => $operation->getType(),
                'user_id' => $operation->getUser()->id,
                'parameters' => $this->sanitizeParameters($operation->getParameters()),
                'result' => $result->toArray(),
                'execution_time' => $operation->getExecutionTime(),
                'memory_peak' => memory_get_peak_usage(true),
                'timestamp' => now()
            ];

            $this->validateOperationLog($logData);
            $this->storeCriticalOperationLog($logData);
            
            if (!$result->isSuccessful()) {
                $this->handleFailedOperation($operation, $result);
            }

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleAuditFailure($e, $logData);
        }
    }

    public function logSystemChange(SystemChange $change): void 
    {
        $changeData = [
            'component' => $change->getComponent(),
            'change_type' => $change->getType(),
            'previous_state' => $change->getPreviousState(),
            'new_state' => $change->getNewState(),
            'initiator' => $change->getInitiator(),
            'timestamp' => now()
        ];

        $this->validateSystemChange($changeData);
        $this->storeSystemChange($changeData);
        
        if ($this->isCriticalChange($change)) {
            $this->notifyCriticalChange($changeData);
        }
    }

    private function prepareEventData(SecurityContext $context, string $event, array $data): array 
    {
        return [
            'event_type' => $event,
            'user_id' => $context->getUser()?->id,
            'ip_address' => $context->getIpAddress(),
            'resource' => $context->getResource(),
            'action' => $context->getAction(),
            'data' => $this->sanitizeData($data),
            'session_id' => $context->getSessionId(),
            'timestamp' => now(),
            'severity' => $this->calculateEventSeverity($event)
        ];
    }

    private function validateEventData(array $data): void 
    {
        $required = ['event_type', 'timestamp', 'severity'];
        
        foreach ($required as $field) {
            if (!isset($data[$field])) {
                throw new AuditValidationException("Missing required field: {$field}");
            }
        }
    }

    private function handleHighRiskEvent(array $eventData): void 
    {
        $this->alerts->triggerHighRiskAlert($eventData);
        $this->logger->critical('High risk security event detected', $eventData);
        
        if ($this->config->get('enhanced_security_mode')) {
            $this->initiateSecurityLockdown($eventData);
        }
    }

    private function handleFailedAccess(SecurityContext $context): void 
    {
        $failures = $this->getRecentFailures($context);
        
        if ($failures >= $this->config->get('max_failures', 5)) {
            $this->initiateAccessBlock($context);
        }
    }

    private function sanitizeData(array $data): array 
    {
        $sanitized = [];
        
        foreach ($data as $key => $value) {
            if (in_array($key, $this->config->get('sensitive_fields', []))) {
                $sanitized[$key] = '[REDACTED]';
            } else {
                $sanitized[$key] = is_array($value) ? 
                    $this->sanitizeData($value) : $value;
            }
        }
        
        return $sanitized;
    }

    private function recordMetrics(string $operation, float $duration): void 
    {
        $this->metrics->record([
            'type' => 'audit',
            'operation' => $operation,
            'duration' => $duration,
            'memory' => memory_get_peak_usage(true)
        ]);
    }

    private function handleAuditFailure(\Exception $e, array $data): void 
    {
        $this->logger->error('Audit system failure', [
            'exception' => $e->getMessage(),
            'data' => $data,
            'trace' => $e->getTraceAsString()
        ]);

        $this->metrics->incrementCounter('audit_failure');
        $this->alerts->notifyAuditFailure($e, $data);
    }
}
