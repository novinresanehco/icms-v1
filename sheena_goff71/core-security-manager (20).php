<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\DB;
use App\Core\Contracts\ValidationServiceInterface;
use App\Core\Contracts\EncryptionServiceInterface;
use App\Core\Contracts\AuditLoggerInterface;
use App\Core\Contracts\MonitoringServiceInterface;
use App\Exceptions\SecurityException;
use App\Exceptions\ValidationException;

class CoreSecurityManager implements SecurityManagerInterface 
{
    private ValidationServiceInterface $validator;
    private EncryptionServiceInterface $encryption;
    private AuditLoggerInterface $auditLogger;
    private MonitoringServiceInterface $monitor;

    public function __construct(
        ValidationServiceInterface $validator,
        EncryptionServiceInterface $encryption,
        AuditLoggerInterface $auditLogger,
        MonitoringServiceInterface $monitor
    ) {
        $this->validator = $validator;
        $this->encryption = $encryption;
        $this->auditLogger = $auditLogger;
        $this->monitor = $monitor;
    }

    /**
     * Execute critical operation with comprehensive security controls
     *
     * @throws SecurityException
     * @throws ValidationException
     */
    public function executeCriticalOperation(callable $operation, array $context): mixed
    {
        // Start monitoring
        $operationId = $this->monitor->startOperation($context);
        
        DB::beginTransaction();
        try {
            // Pre-execution validation
            $this->validateContext($context);
            
            // Execute with monitoring
            $result = $this->executeWithProtection($operation, $context, $operationId);
            
            // Validate result
            $this->validateResult($result);
            
            DB::commit();
            $this->auditLogger->logSuccess($context, $result);
            
            return $result;
            
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->handleFailure($e, $context, $operationId);
            throw $e;
        } finally {
            $this->monitor->stopOperation($operationId);
        }
    }

    private function validateContext(array $context): void
    {
        if (!$this->validator->validateContext($context)) {
            throw new ValidationException('Invalid operation context');
        }

        if (!$this->validator->checkSecurityConstraints($context)) {
            throw new SecurityException('Security constraints not met');
        }
    }

    private function executeWithProtection(callable $operation, array $context, string $operationId): mixed
    {
        return $this->monitor->track($operationId, function() use ($operation, $context) {
            // Encrypt sensitive data
            $secureContext = $this->encryption->encryptSensitiveData($context);
            
            // Execute operation
            $result = $operation($secureContext);
            
            // Verify result integrity
            if (!$this->encryption->verifyIntegrity($result)) {
                throw new SecurityException('Result integrity check failed');
            }
            
            return $result;
        });
    }

    private function validateResult($result): void
    {
        if (!$this->validator->validateResult($result)) {
            throw new ValidationException('Operation result validation failed');
        }
    }

    private function handleFailure(\Throwable $e, array $context, string $operationId): void
    {
        // Log comprehensive failure details
        $this->auditLogger->logFailure($e, $context, [
            'operation_id' => $operationId,
            'stack_trace' => $e->getTraceAsString(),
            'system_state' => $this->monitor->getSystemState()
        ]);

        // Execute emergency protocols if needed
        if ($this->isEmergencyProtocolNeeded($e)) {
            $this->executeEmergencyProtocol($e, $context);
        }
    }

    private function isEmergencyProtocolNeeded(\Throwable $e): bool
    {
        return $e instanceof SecurityException || 
               $e instanceof \PDOException ||
               $e instanceof \ErrorException;
    }

    private function executeEmergencyProtocol(\Throwable $e, array $context): void
    {
        // Implementation depends on specific emergency requirements
        // But must be handled without throwing exceptions
    }
}
