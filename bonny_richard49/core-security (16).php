<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use App\Core\Interfaces\SecurityManagerInterface;
use App\Core\Security\Services\{
    ValidationService,
    EncryptionService,
    AuditService,
    AuthorizationService
};

/**
 * Critical security manager handling all system security operations
 * with comprehensive auditing and protection.
 */
class SecurityManager implements SecurityManagerInterface
{
    private ValidationService $validator;
    private EncryptionService $encryption;
    private AuditService $audit;
    private AuthorizationService $auth;
    private array $securityConfig;

    public function __construct(
        ValidationService $validator,
        EncryptionService $encryption,
        AuditService $audit,
        AuthorizationService $auth,
        array $securityConfig
    ) {
        $this->validator = $validator;
        $this->encryption = $encryption;
        $this->audit = $audit;
        $this->auth = $auth;
        $this->securityConfig = $securityConfig;
    }

    /**
     * Execute critical operation with complete security controls
     * 
     * @throws SecurityException
     * @throws ValidationException 
     */
    public function executeCriticalOperation(CriticalOperation $operation, SecurityContext $context): OperationResult 
    {
        DB::beginTransaction();
        $operationId = $this->generateOperationId();
        
        try {
            // Pre-execution validation
            $this->validateOperation($operation, $context);
            
            // Execute with full monitoring
            $result = $this->executeSecured($operation, $context, $operationId);
            
            // Post-execution verification
            $this->verifyResult($result, $context);
            
            DB::commit();
            
            $this->audit->logSuccess($operation, $context, $operationId);
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFailure($e, $operation, $context, $operationId);
            throw new SecurityException('Operation failed: ' . $e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Validate operation before execution
     */
    private function validateOperation(CriticalOperation $operation, SecurityContext $context): void
    {
        // Input validation
        if (!$this->validator->validateInput($operation->getData())) {
            throw new ValidationException('Invalid operation input');
        }

        // Permission check
        if (!$this->auth->hasPermission($context, $operation->getRequiredPermissions())) {
            $this->audit->logUnauthorized($context, $operation);
            throw new UnauthorizedException('Insufficient permissions');
        }

        // Rate limiting
        if (!$this->auth->checkRateLimit($context)) {
            $this->audit->logRateLimitExceeded($context);
            throw new RateLimitException('Rate limit exceeded');
        }

        // Additional security checks
        $this->performSecurityChecks($operation, $context);
    }

    /**
     * Execute operation with security monitoring
     */
    private function executeSecured(CriticalOperation $operation, SecurityContext $context, string $operationId): OperationResult
    {
        $monitor = $this->initializeSecurityMonitor($operationId);
        
        try {
            $result = $monitor->track(function() use ($operation) {
                return $operation->execute();
            });

            if (!$this->validator->validateResult($result)) {
                throw new ValidationException('Invalid operation result');
            }

            return $result;
        } catch (\Exception $e) {
            $monitor->recordFailure($e);
            throw $e;
        }
    }

    /**
     * Verify operation result
     */
    private function verifyResult(OperationResult $result, SecurityContext $context): void
    {
        // Data integrity check
        if (!$this->validator->verifyIntegrity($result)) {
            throw new IntegrityException('Result integrity verification failed');
        }

        // Business rules validation
        if (!$this->validator->verifyBusinessRules($result)) {
            throw new ValidationException('Business rule validation failed');
        }

        // Permission check for result data
        if (!$this->auth->canAccessResult($context, $result)) {
            throw new UnauthorizedException('Unauthorized result access');
        }
    }

    /**
     * Handle operation failure with comprehensive logging
     */
    private function handleFailure(\Exception $e, CriticalOperation $operation, SecurityContext $context, string $operationId): void
    {
        $this->audit->logFailure(
            $e,
            $operation,
            $context,
            $operationId,
            [
                'stack_trace' => $e->getTraceAsString(),
                'system_state' => $this->captureSystemState()
            ]
        );

        if ($e instanceof SecurityException) {
            $this->handleSecurityFailure($e, $context);
        }

        // Implement recovery procedures if needed
        $this->executeFailureRecovery($operation, $context, $e);
    }

    /**
     * Perform additional security validations
     */
    private function performSecurityChecks(CriticalOperation $operation, SecurityContext $context): void
    {
        // IP whitelist check if required
        if ($operation->requiresIpWhitelist()) {
            $this->auth->verifyIpWhitelist($context->getIpAddress());
        }

        // Check for suspicious patterns
        if ($this->detectSuspiciousActivity($context)) {
            $this->audit->logSuspiciousActivity($context);
            throw new SecurityException('Suspicious activity detected');
        }

        // Verify all security requirements
        foreach ($operation->getSecurityRequirements() as $requirement) {
            if (!$this->validateSecurityRequirement($requirement, $context)) {
                throw new SecurityException("Security requirement not met: {$requirement}");
            }
        }
    }

    /**
     * Initialize security monitoring
     */
    private function initializeSecurityMonitor(string $operationId): SecurityMonitor
    {
        return new SecurityMonitor(
            $operationId,
            $this->audit,
            $this->securityConfig['monitoring']
        );
    }

    /**
     * Generate unique operation ID
     */
    private function generateOperationId(): string
    {
        return sprintf(
            '%s-%s',
            date('YmdHis'),
            bin2hex(random_bytes(8))
        );
    }

    /**
     * Capture current system state for diagnostics
     */
    private function captureSystemState(): array
    {
        return [
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
            'system_load' => sys_getloadavg(),
            'timestamp' => microtime(true)
        ];
    }
}
