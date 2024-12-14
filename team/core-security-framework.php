<?php

namespace App\Core\Security;

/**
 * Core security implementation for critical CMS functionality
 */
class CoreSecurityManager implements SecurityManagerInterface
{
    private EncryptionService $encryption;
    private ValidationService $validator;
    private AuditLogger $auditLogger;
    private AccessControl $accessControl;

    public function __construct(
        EncryptionService $encryption,
        ValidationService $validator,
        AuditLogger $auditLogger,
        AccessControl $accessControl
    ) {
        $this->encryption = $encryption;
        $this->validator = $validator;
        $this->auditLogger = $auditLogger;
        $this->accessControl = $accessControl;
    }

    public function executeCriticalOperation(callable $operation, SecurityContext $context): mixed
    {
        DB::beginTransaction();
        
        try {
            // Pre-execution validation
            $this->validateOperation($context);
            
            // Execute with full monitoring
            $result = $this->executeWithProtection($operation, $context);
            
            // Verify operation result
            $this->validateResult($result, $context);
            
            DB::commit();
            return $result;
            
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->handleFailure($e, $context);
            throw $e;
        }
    }

    private function validateOperation(SecurityContext $context): void
    {
        // Validate authentication
        if (!$this->accessControl->verifyAuthentication($context)) {
            $this->auditLogger->logAuthFailure($context);
            throw new SecurityException('Authentication failed');
        }

        // Check authorization
        if (!$this->accessControl->hasPermission($context)) {
            $this->auditLogger->logUnauthorizedAccess($context);
            throw new SecurityException('Unauthorized access attempt');
        }

        // Validate input data
        if (!$this->validator->validateInput($context->getData())) {
            $this->auditLogger->logValidationFailure($context);
            throw new ValidationException('Input validation failed');
        }
    }

    private function executeWithProtection(callable $operation, SecurityContext $context): mixed
    {
        // Start operation monitoring
        $monitorId = $this->startMonitoring($context);
        
        try {
            // Execute the operation
            $result = $operation();
            
            // Log successful execution
            $this->auditLogger->logSuccess($context, $monitorId);
            
            return $result;
            
        } catch (\Throwable $e) {
            // Log operation failure
            $this->auditLogger->logFailure($e, $context, $monitorId);
            throw $e;
        } finally {
            // Always stop monitoring
            $this->stopMonitoring($monitorId);
        }
    }

    private function validateResult($result, SecurityContext $context): void
    {
        // Validate result integrity
        if (!$this->validator->validateResult($result)) {
            throw new ValidationException('Result validation failed');
        }

        // Encrypt sensitive data if needed
        if ($context->requiresEncryption()) {
            $result = $this->encryption->encryptSensitiveData($result);
        }
    }

    private function handleFailure(\Throwable $e, SecurityContext $context): void
    {
        // Log comprehensive failure details
        $this->auditLogger->logCriticalFailure($e, [
            'context' => $context,
            'trace' => $e->getTraceAsString(),
            'system_state' => $this->captureSystemState()
        ]);

        // Notify security team for critical failures
        if ($this->isCriticalSecurity($e)) {
            $this->notifySecurityTeam($e, $context);
        }
    }

    private function startMonitoring(SecurityContext $context): string
    {
        return $this->auditLogger->startOperationMonitoring([
            'operation' => $context->getOperation(),
            'user' => $context->getUser(),
            'timestamp' => now(),
            'resource' => $context->getResource()
        ]);
    }

    private function stopMonitoring(string $monitorId): void
    {
        $this->auditLogger->stopOperationMonitoring($monitorId, [
            'end_time' => now(),
            'system_state' => $this->captureSystemState()
        ]);
    }

    private function captureSystemState(): array
    {
        return [
            'memory_usage' => memory_get_usage(true),
            'cpu_load' => sys_getloadavg(),
            'active_connections' => $this->getActiveConnections(),
            'error_state' => error_get_last()
        ];
    }

    private function isCriticalSecurity(\Throwable $e): bool
    {
        return $e instanceof SecurityException && 
               $e->getSeverity() === SecurityException::CRITICAL;
    }

    private function notifySecurityTeam(\Throwable $e, SecurityContext $context): void
    {
        // Implementation depends on notification system
        // but must be handled without throwing exceptions
    }
}
