<?php

namespace App\Core\Security;

use App\Core\Interfaces\SecurityManagerInterface;
use App\Core\Exceptions\{SecurityException, ValidationException, UnauthorizedException};
use Illuminate\Support\Facades\{DB, Log};

class CoreSecurityManager implements SecurityManagerInterface
{
    private ValidationService $validator;
    private EncryptionService $encryption;
    private AuditLogger $auditLogger;
    private AccessControl $accessControl;
    private SecurityConfig $config;

    public function __construct(
        ValidationService $validator,
        EncryptionService $encryption,
        AuditLogger $auditLogger,
        AccessControl $accessControl,
        SecurityConfig $config
    ) {
        $this->validator = $validator;
        $this->encryption = $encryption;
        $this->auditLogger = $auditLogger;
        $this->accessControl = $accessControl;
        $this->config = $config;
    }

    public function executeCriticalOperation(CriticalOperation $operation, SecurityContext $context): OperationResult
    {
        DB::beginTransaction();
        $startTime = microtime(true);
        
        try {
            // Validate operation security context
            $this->validateOperation($operation, $context);
            
            // Execute operation with monitoring
            $result = $this->executeWithProtection($operation, $context);
            
            // Verify result integrity
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
        // Validate input data
        $this->validator->validateInput(
            $operation->getData(),
            $operation->getValidationRules()
        );

        // Check permissions
        if (!$this->accessControl->hasPermission($context, $operation->getRequiredPermissions())) {
            $this->auditLogger->logUnauthorizedAccess($context, $operation);
            throw new UnauthorizedException('Insufficient permissions');
        }

        // Verify rate limits
        if (!$this->accessControl->checkRateLimit($context, $operation->getRateLimitKey())) {
            $this->auditLogger->logRateLimitExceeded($context, $operation);
            throw new RateLimitException('Rate limit exceeded');
        }
    }

    private function executeWithProtection(CriticalOperation $operation, SecurityContext $context): OperationResult
    {
        $monitor = new OperationMonitor($operation, $context);
        
        try {
            $result = $monitor->execute(function() use ($operation) {
                return $operation->execute();
            });

            if (!$result->isValid()) {
                throw new OperationException('Invalid operation result');
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
    }

    private function handleFailure(CriticalOperation $operation, SecurityContext $context, \Exception $e): void
    {
        $this->auditLogger->logOperationFailure($operation, $context, $e, [
            'stack_trace' => $e->getTraceAsString(),
            'input_data' => $operation->getData(),
            'system_state' => $this->captureSystemState()
        ]);

        $this->notifyFailure($operation, $context, $e);
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
