<?php

namespace App\Core\Security;

/**
 * Core security manager handling all critical security operations
 * with comprehensive audit logging and failure protection.
 */
class SecurityManager implements SecurityManagerInterface 
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

    /**
     * Validates and executes a critical operation with comprehensive protection
     *
     * @param CriticalOperation $operation The operation to execute
     * @param SecurityContext $context Security context including user and permissions
     * @throws SecurityException If any security validation fails
     * @throws ValidationException If input validation fails
     * @return OperationResult The result of the operation
     */
    public function executeCriticalOperation(
        CriticalOperation $operation,
        SecurityContext $context
    ): OperationResult {
        // Start transaction and metrics collection
        DB::beginTransaction();
        $startTime = microtime(true);
        
        try {
            // Pre-execution validation
            $this->validateOperation($operation, $context);
            
            // Execute operation with monitoring
            $result = $this->executeWithProtection($operation, $context);
            
            // Verify result integrity
            $this->verifyResult($result);
            
            // Commit and log success
            DB::commit();
            $this->logSuccess($operation, $context, $result);
            
            return $result;
            
        } catch (\Exception $e) {
            // Rollback and handle failure
            DB::rollBack();
            $this->handleFailure($operation, $context, $e);
            throw new SecurityException(
                'Critical operation failed: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        } finally {
            // Record metrics
            $this->recordMetrics($operation, microtime(true) - $startTime);
        }
    }

    /**
     * Core encryption functionality for sensitive data
     */
    public function encrypt(string $data, string $context = ''): string {
        $encryptionKey = $this->getContextualEncryptionKey($context);
        return $this->encryption->encrypt($data, $encryptionKey);
    }

    public function decrypt(string $encrypted, string $context = ''): string {
        $encryptionKey = $this->getContextualEncryptionKey($context);
        return $this->encryption->decrypt($encrypted, $encryptionKey);
    }

    /**
     * Session and token management
     */
    public function validateToken(string $token): TokenValidation {
        try {
            return $this->accessControl->validateToken($token);
        } catch (\Exception $e) {
            $this->auditLogger->logFailedTokenValidation($token, $e);
            throw new InvalidTokenException('Token validation failed');
        }
    }

    public function refreshToken(string $token): string {
        $validation = $this->validateToken($token);
        if ($validation->shouldRefresh()) {
            return $this->accessControl->refreshToken($token);
        }
        return $token;
    }

    /**
     * Validates an operation before execution
     */
    private function validateOperation(
        CriticalOperation $operation,
        SecurityContext $context
    ): void {
        // Validate input data
        if (!$this->validator->validateInput($operation->getData())) {
            throw new ValidationException('Invalid operation input data');
        }

        // Check permissions
        if (!$this->accessControl->hasPermission($context, $operation->getRequiredPermissions())) {
            $this->auditLogger->logUnauthorizedAccess($context, $operation);
            throw new UnauthorizedException('Insufficient permissions for operation');
        }

        // Verify rate limits
        if (!$this->accessControl->checkRateLimit($context, $operation->getRateLimitKey())) {
            $this->auditLogger->logRateLimitExceeded($context, $operation);
            throw new RateLimitException('Rate limit exceeded for operation');
        }

        // Additional security checks
        $this->performSecurityChecks($operation, $context);
    }

    /**
     * Executes an operation with comprehensive monitoring
     */
    private function executeWithProtection(
        CriticalOperation $operation,
        SecurityContext $context
    ): OperationResult {
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

    /**
     * Handles operation failures with comprehensive logging
     */
    private function handleFailure(
        CriticalOperation $operation,
        SecurityContext $context,
        \Exception $e
    ): void {
        // Log detailed failure information
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

        // Notify relevant parties
        $this->notifyFailure($operation, $context, $e);

        // Update metrics
        $this->metrics->incrementFailureCount(
            $operation->getType(),
            $e->getCode()
        );

        // Execute failure recovery if needed
        $this->executeFailureRecovery($operation, $context, $e);
    }

    /**
     * Records comprehensive metrics for the operation
     */
    private function recordMetrics(
        CriticalOperation $operation,
        float $executionTime
    ): void {
        $this->metrics->record([
            'operation_type' => $operation->getType(),
            'execution_time' => $executionTime,
            'memory_usage' => memory_get_peak_usage(true),
            'cpu_usage' => sys_getloadavg()[0],
            'timestamp' => microtime(true)
        ]);
    }

    /**
     * Gets encryption key based on context
     */
    private function getContextualEncryptionKey(string $context): string {
        return $this->encryption->deriveKey(
            $this->config->getBaseEncryptionKey(),
            $context
        );
    }

    /**
     * Performs additional security checks specific to the operation
     */
    private function performSecurityChecks(
        CriticalOperation $operation,
        SecurityContext $context
    ): void {
        // Verify IP whitelist if required
        if ($operation->requiresIpWhitelist()) {
            $this->accessControl->verifyIpWhitelist($context->getIpAddress());
        }

        // Check for suspicious patterns
        if ($this->detectSuspiciousActivity($context)) {
            $this->auditLogger->logSuspiciousActivity($context, $operation);
            throw new SecurityException('Suspicious activity detected');
        }

        // Verify additional security requirements
        foreach ($operation->getSecurityRequirements() as $requirement) {
            if (!$this->validateSecurityRequirement($requirement, $context)) {
                throw new SecurityException("Security requirement not met: {$requirement}");
            }
        }
    }
}
