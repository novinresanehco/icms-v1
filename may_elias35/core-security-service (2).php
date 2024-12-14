<?php

namespace App\Core\Security\Service;

use App\Core\Security\Encryption\EncryptionService;
use App\Core\Security\Validation\ValidationService;
use App\Core\Monitoring\HealthCheck\SystemMonitor;
use App\Core\Security\Auth\AccessControl;
use App\Core\Audit\AuditLogger;
use App\Core\Exceptions\SecurityException;

/**
 * Core security service handling all critical security operations
 * with comprehensive protection mechanisms and audit logging.
 */
class CoreSecurityService implements SecurityServiceInterface 
{
    private EncryptionService $encryption;
    private ValidationService $validator;
    private SystemMonitor $monitor;
    private AccessControl $access;
    private AuditLogger $audit;

    public function __construct(
        EncryptionService $encryption,
        ValidationService $validator,
        SystemMonitor $monitor,
        AccessControl $access,
        AuditLogger $audit
    ) {
        $this->encryption = $encryption;
        $this->validator = $validator;
        $this->monitor = $monitor;
        $this->access = $access;
        $this->audit = $audit;
    }

    /**
     * Executes a critical security operation with comprehensive protection
     *
     * @param SecurityOperation $operation Operation to execute
     * @param SecurityContext $context Security context details
     * @throws SecurityException If operation fails security checks
     * @return OperationResult The result of the operation
     */
    public function executeSecureOperation(
        SecurityOperation $operation,
        SecurityContext $context
    ): OperationResult {
        // Start transaction and monitoring
        DB::beginTransaction();
        $monitoringId = $this->monitor->startOperation($context);

        try {
            // Verify system security state
            $this->verifySystemSecurity();

            // Validate operation and context
            $this->validateOperation($operation, $context);

            // Execute with protection
            $result = $this->executeWithProtection($operation, $context);

            // Verify result integrity
            $this->verifyResultIntegrity($result);

            // Commit and audit
            DB::commit();
            $this->audit->logSecureOperation($operation, $result, $context);

            return $result;

        } catch (\Exception $e) {
            // Rollback and handle security failure
            DB::rollBack();
            $this->handleSecurityFailure($e, $operation, $context);
            throw new SecurityException(
                'Security operation failed: ' . $e->getMessage(),
                previous: $e
            );
        } finally {
            // Always stop monitoring
            $this->monitor->stopOperation($monitoringId);
        }
    }

    /**
     * Verifies the security state of the system
     */
    private function verifySystemSecurity(): void
    {
        // Check system health
        $healthStatus = $this->monitor->checkSystemHealth();
        if (!$healthStatus->isHealthy()) {
            throw new SecurityStateException('System health check failed');
        }

        // Verify security components
        if (!$this->verifySecurityComponents()) {
            throw new SecurityStateException('Security component verification failed');
        }

        // Check for security threats
        if ($this->monitor->hasActiveThreats()) {
            throw new SecurityThreatException('Active security threats detected');
        }
    }

    /**
     * Validates the operation and security context
     */
    private function validateOperation(
        SecurityOperation $operation,
        SecurityContext $context
    ): void {
        // Validate operation parameters
        if (!$this->validator->validateOperation($operation)) {
            throw new ValidationException('Invalid security operation');
        }

        // Check permissions
        if (!$this->access->hasPermission($context, $operation->getRequiredPermissions())) {
            throw new AccessDeniedException('Insufficient permissions');
        }

        // Verify operation integrity
        if (!$this->verifyOperationIntegrity($operation)) {
            throw new IntegrityException('Operation integrity check failed');
        }
    }

    /**
     * Executes operation with protection mechanisms
     */
    private function executeWithProtection(
        SecurityOperation $operation,
        SecurityContext $context
    ): OperationResult {
        // Create protected execution context
        $protectedContext = $this->createProtectedContext($operation, $context);

        try {
            // Execute in protected mode
            return $operation->execute($protectedContext);

        } catch (\Exception $e) {
            // Handle execution failure
            $this->handleExecutionFailure($e, $operation);
            throw $e;
        }
    }

    /**
     * Verifies the integrity of operation result
     */
    private function verifyResultIntegrity(OperationResult $result): void
    {
        // Verify data integrity
        if (!$this->validator->verifyResultIntegrity($result)) {
            throw new IntegrityException('Result integrity verification failed');
        }

        // Check cryptographic properties
        if (!$this->encryption->verifyCryptographicProperties($result)) {
            throw new CryptoException('Cryptographic verification failed');
        }

        // Validate business rules
        if (!$this->validator->validateBusinessRules($result)) {
            throw new ValidationException('Business rule validation failed');
        }
    }

    /**
     * Handles security operation failures
     */
    private function handleSecurityFailure(
        \Exception $e,
        SecurityOperation $operation,
        SecurityContext $context
    ): void {
        // Log security incident
        $this->audit->logSecurityIncident(
            'security_operation_failed',
            [
                'operation' => $operation->getId(),
                'error' => $e->getMessage(),
                'context' => $context->toArray(),
                'trace' => $e->getTraceAsString()
            ]
        );

        // Update security metrics
        $this->monitor->recordSecurityFailure([
            'type' => get_class($e),
            'operation' => $operation->getId(),
            'timestamp' => now()
        ]);

        // Execute security protocols
        $this->executeSecurityProtocols($e, $operation);
    }

    /**
     * Creates protected execution context for operation
     */
    private function createProtectedContext(
        SecurityOperation $operation,
        SecurityContext $context
    ): ProtectedContext {
        return new ProtectedContext(
            operation: $operation,
            context: $context,
            encryption: $this->encryption,
            validator: $this->validator,
            monitor: $this->monitor
        );
    }

    /**
     * Verifies security components status
     */
    private function verifySecurityComponents(): bool
    {
        return $this->encryption->isOperational() &&
               $this->validator->isOperational() &&
               $this->access->isOperational() &&
               $this->monitor->isOperational();
    }

    /**
     * Verifies operation integrity
     */
    private function verifyOperationIntegrity(SecurityOperation $operation): bool
    {
        return $this->validator->verifyOperationIntegrity($operation) &&
               $this->encryption->verifyOperationSecurity($operation);
    }

    /**
     * Handles operation execution failures
     */
    private function handleExecutionFailure(\Exception $e, SecurityOperation $operation): void
    {
        $this->audit->logExecutionFailure($e, $operation);
        $this->monitor->recordExecutionFailure($operation);
    }

    /**
     * Executes security protocols for failures
     */
    private function executeSecurityProtocols(\Exception $e, SecurityOperation $operation): void
    {
        // Implement specific security protocols based on failure type
        if ($e instanceof SecurityThreatException) {
            $this->monitor->activateSecurityProtocols($operation);
        }
        
        if ($e instanceof IntegrityException) {
            $this->monitor->activateDataProtectionProtocols();
        }
    }
}
