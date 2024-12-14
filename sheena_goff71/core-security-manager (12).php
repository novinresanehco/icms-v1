<?php

namespace App\Core\Security;

use App\Core\Interfaces\SecurityManagerInterface;
use App\Core\Services\{ValidationService, EncryptionService, AuditLogger};
use App\Core\Security\{AccessControl, SecurityConfig};
use App\Core\Monitoring\MetricsCollector;
use Illuminate\Support\Facades\DB;

class CoreSecurityManager implements SecurityManagerInterface 
{
    private ValidationService $validator;
    private EncryptionService $encryption;
    private AuditLogger $auditLogger;
    private AccessControl $accessControl;
    private SecurityConfig $config;
    private MetricsCollector $metrics;

    public function __construct(
        ValidationService $validator,
        EncryptionService $encryption,
        AuditLogger $auditLogger,
        AccessControl $accessControl,
        SecurityConfig $config,
        MetricsCollector $metrics
    ) {
        $this->validator = $validator;
        $this->encryption = $encryption;
        $this->auditLogger = $auditLogger;
        $this->accessControl = $accessControl;
        $this->config = $config;
        $this->metrics = $metrics;
    }

    public function executeCriticalOperation(CriticalOperation $operation, SecurityContext $context): OperationResult 
    {
        DB::beginTransaction();
        $startTime = microtime(true);
        
        try {
            $this->validateOperation($operation, $context);
            $result = $this->executeWithProtection($operation, $context);
            $this->verifyResult($result);
            
            DB::commit();
            $this->logSuccess($operation, $context, $result);
            
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFailure($operation, $context, $e);
            throw new SecurityException(
                'Critical operation failed: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        } finally {
            $this->recordMetrics($operation, microtime(true) - $startTime);
        }
    }

    private function validateOperation(CriticalOperation $operation, SecurityContext $context): void
    {
        $this->validator->validateInput(
            $operation->getData(),
            $operation->getValidationRules()
        );

        if (!$this->accessControl->hasPermission($context, $operation->getRequiredPermissions())) {
            $this->auditLogger->logUnauthorizedAccess($context, $operation);
            throw new UnauthorizedException('Insufficient permissions for operation');
        }

        if (!$this->accessControl->checkRateLimit($context, $operation->getRateLimitKey())) {
            $this->auditLogger->logRateLimitExceeded($context, $operation);
            throw new RateLimitException('Rate limit exceeded for operation');
        }

        $this->performSecurityChecks($operation, $context);
    }

    private function executeWithProtection(CriticalOperation $operation, SecurityContext $context): OperationResult 
    {
        $monitor = new OperationMonitor($operation, $context);
        
        try {
            $result = $monitor->execute(function() use ($operation) {
                return $operation->execute();
            });

            if (!$result->isValid()) {
                throw new OperationException('Operation produced invalid result');
            }

            return $result;
            
        } catch (\Exception $e) {
            $monitor->recordFailure($e);
            throw $e;
        }
    }

    private function verifyResult(OperationResult $result): void
    {
        if (!$this->validator->verifyIntegrity($result)) {
            throw new IntegrityException('Result integrity check failed');
        }

        if (!$this->validator->verifyBusinessRules($result)) {
            throw new BusinessRuleException('Business rule validation failed');
        }

        $this->performResultValidation($result);
    }

    private function handleFailure(CriticalOperation $operation, SecurityContext $context, \Exception $e): void
    {
        $this->auditLogger->logOperationFailure(
            $operation,
            $context,
            $e,
            [
                'stack_trace' => $e->getTraceAsString(),
                'input_data' => $operation->getData(),
                'system_state' => $this->captureSystemState()
            ]
        );

        $this->notifyFailure($operation, $context, $e);
        
        $this->metrics->incrementFailureCount(
            $operation->getType(),
            $e->getCode()
        );

        $this->executeFailureRecovery($operation, $context, $e);
    }

    private function recordMetrics(CriticalOperation $operation, float $executionTime): void
    {
        $this->metrics->record([
            'operation_type' => $operation->getType(),
            'execution_time' => $executionTime,
            'memory_usage' => memory_get_peak_usage(true),
            'cpu_usage' => sys_getloadavg()[0],
            'timestamp' => microtime(true)
        ]);
    }

    private function performSecurityChecks(CriticalOperation $operation, SecurityContext $context): void
    {
        if ($operation->requiresIpWhitelist()) {
            $this->accessControl->verifyIpWhitelist($context->getIpAddress());
        }

        if ($this->detectSuspiciousActivity($context)) {
            $this->auditLogger->logSuspiciousActivity($context, $operation);
            throw new SecurityException('Suspicious activity detected');
        }

        foreach ($operation->getSecurityRequirements() as $requirement) {
            if (!$this->validateSecurityRequirement($requirement, $context)) {
                throw new SecurityException("Security requirement not met: {$requirement}");
            }
        }
    }
}
