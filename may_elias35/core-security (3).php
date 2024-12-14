<?php
namespace App\Core\Security;

use Illuminate\Support\Facades\{DB, Cache, Log};
use App\Core\Interfaces\{SecurityManagerInterface, ValidationInterface};
use App\Core\Security\{AccessControl, AuditLogger, EncryptionService};
use App\Core\Exceptions\{SecurityException, ValidationException};

class SecurityManager implements SecurityManagerInterface 
{
    private ValidationService $validator;
    private EncryptionService $encryption;
    private AccessControl $access;
    private AuditLogger $audit;
    
    public function __construct(
        ValidationService $validator,
        EncryptionService $encryption, 
        AccessControl $access,
        AuditLogger $audit
    ) {
        $this->validator = $validator;
        $this->encryption = $encryption;
        $this->access = $access;
        $this->audit = $audit;
    }

    /**
     * Executes a critical operation with full security controls
     * 
     * @throws SecurityException
     */
    public function executeCriticalOperation(callable $operation, SecurityContext $context): mixed
    {
        DB::beginTransaction();
        $startTime = microtime(true);
        
        try {
            // Pre-execution security validation
            $this->validateSecurity($context);
            
            // Execute operation with monitoring
            $result = $this->monitorExecution($operation, $context);
            
            // Verify operation results
            $this->verifyResult($result);
            
            DB::commit();
            $this->audit->logSuccess($context, $result);
            
            return $result;
            
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->handleSecurityFailure($e, $context);
            throw new SecurityException(
                'Security validation failed: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        } finally {
            // Record metrics
            $this->recordMetrics($context, microtime(true) - $startTime);
        }
    }

    /**
     * Validates complete security context before execution
     */
    private function validateSecurity(SecurityContext $context): void
    {
        // Validate authentication
        if (!$this->access->validateAuthentication($context)) {
            $this->audit->logAuthFailure($context);
            throw new SecurityException('Invalid authentication');
        }

        // Verify authorization
        if (!$this->access->checkAuthorization($context)) {
            $this->audit->logAuthzFailure($context);
            throw new SecurityException('Unauthorized access attempt');
        }

        // Check rate limits
        if (!$this->access->checkRateLimit($context)) {
            throw new SecurityException('Rate limit exceeded');
        }

        // Validate input data
        $this->validator->validateInput($context->getInput());
    }

    /**
     * Monitors operation execution with security controls
     */
    private function monitorExecution(callable $operation, SecurityContext $context): mixed
    {
        $monitor = new SecurityMonitor($context);
        
        try {
            return $monitor->track(function() use ($operation) {
                return $operation();
            });
        } catch (\Throwable $e) {
            $monitor->recordFailure($e);
            throw $e;
        }
    }

    /**
     * Verifies operation results meet security requirements
     */
    private function verifyResult($result): void
    {
        if (!$this->validator->validateOutput($result)) {
            throw new ValidationException('Output validation failed');
        }

        if (!$this->encryption->verifyIntegrity($result)) {
            throw new SecurityException('Result integrity check failed');
        }
    }

    /**
     * Handles security failures with complete audit trail
     */
    private function handleSecurityFailure(\Throwable $e, SecurityContext $context): void
    {
        $this->audit->logFailure($e, $context, [
            'stack_trace' => $e->getTraceAsString(),
            'system_state' => $this->captureSystemState()
        ]);

        if ($this->isCriticalFailure($e)) {
            $this->notifySecurityTeam($e, $context);
        }
    }

    /**
     * Records comprehensive security metrics
     */
    private function recordMetrics(SecurityContext $context, float $executionTime): void
    {
        $metrics = [
            'execution_time' => $executionTime,
            'memory_usage' => memory_get_peak_usage(true),
            'user_id' => $context->getUserId(),
            'operation' => $context->getOperation(),
            'timestamp' => microtime(true)
        ];

        Cache::tags('security_metrics')->put(
            'operation_' . uniqid(),
            $metrics,
            now()->addDays(30)
        );
    }
}
