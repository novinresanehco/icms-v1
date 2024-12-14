<?php

namespace App\Core\Security;

use App\Core\Contracts\{SecurityManagerInterface, ValidationServiceInterface};
use App\Core\Services\{EncryptionService, AuditLogger};
use App\Core\Security\{AccessControl, SecurityConfig};
use App\Core\Monitoring\MetricsCollector;
use Illuminate\Support\Facades\DB;

class CoreSecurityManager implements SecurityManagerInterface
{
    private ValidationServiceInterface $validator;
    private EncryptionService $encryption;
    private AuditLogger $auditLogger;
    private AccessControl $accessControl;
    private SecurityConfig $config;
    private MetricsCollector $metrics;

    public function __construct(
        ValidationServiceInterface $validator,
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
            // Pre-execution validation
            $this->validateOperation($operation, $context);
            
            // Execute with comprehensive monitoring
            $result = $this->executeWithProtection($operation, $context);
            
            // Post-execution verification
            $this->verifyResult($result);
            
            DB::commit();
            $this->logSuccess($operation, $context, $result);
            
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFailure($operation, $context, $e);
            throw $e;
        } finally {
            $this->recordMetrics($operation, microtime(true) - $startTime);
        }
    }

    private function validateOperation(CriticalOperation $operation, SecurityContext $context): void 
    {
        // Validate input data
        if (!$this->validator->validateInput($operation->getData())) {
            throw new ValidationException('Invalid operation data');
        }

        // Verify permissions
        if (!$this->accessControl->hasPermission($context, $operation->getRequiredPermissions())) {
            $this->auditLogger->logUnauthorizedAccess($context);
            throw new UnauthorizedException('Insufficient permissions');
        }

        // Check rate limits
        if (!$this->accessControl->checkRateLimit($context)) {
            throw new RateLimitException('Rate limit exceeded');
        }
    }

    private function executeWithProtection(CriticalOperation $operation, SecurityContext $context): OperationResult 
    {
        $monitor = new OperationMonitor($operation, $context);
        
        try {
            return $monitor->execute(function() use ($operation) {
                return $operation->execute();
            });
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
    }

    private function handleFailure(CriticalOperation $operation, SecurityContext $context, \Exception $e): void 
    {
        $this->auditLogger->logOperationFailure($operation, $context, $e);
        $this->metrics->incrementFailureCount($operation->getType());
        $this->notifyFailure($operation, $e);
    }

    private function recordMetrics(CriticalOperation $operation, float $executionTime): void 
    {
        $this->metrics->record([
            'operation_type' => $operation->getType(),
            'execution_time' => $executionTime,
            'memory_usage' => memory_get_peak_usage(true),
            'timestamp' => microtime(true)
        ]);
    }
}
