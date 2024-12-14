<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\DB;
use App\Core\Interfaces\{
    SecurityManagerInterface,
    ValidationServiceInterface,
    AuditLoggerInterface,
    MonitoringServiceInterface
};
use App\Core\Exceptions\{
    SecurityException,
    ValidationException,
    UnauthorizedException
};

class SecurityManager implements SecurityManagerInterface
{
    private ValidationServiceInterface $validator;
    private AuditLoggerInterface $auditLogger;
    private MonitoringServiceInterface $monitor;
    private array $config;

    public function __construct(
        ValidationServiceInterface $validator,
        AuditLoggerInterface $auditLogger,
        MonitoringServiceInterface $monitor,
        array $config
    ) {
        $this->validator = $validator;
        $this->auditLogger = $auditLogger;
        $this->monitor = $monitor;
        $this->config = $config;
    }

    public function executeCriticalOperation(callable $operation, array $context): mixed
    {
        $operationId = $this->monitor->startOperation($context);
        DB::beginTransaction();

        try {
            // Pre-execution validation
            $this->validateContext($context);
            
            // Execute with monitoring
            $result = $this->monitor->trackExecution($operationId, $operation);
            
            // Validate result
            if (!$this->validator->validateResult($result)) {
                throw new ValidationException('Operation result validation failed');
            }

            DB::commit();
            $this->auditLogger->logSuccess($operationId, $context, $result);
            
            return $result;

        } catch (\Throwable $e) {
            DB::rollBack();
            $this->handleFailure($e, $context, $operationId);
            throw new SecurityException(
                'Critical operation failed: ' . $e->getMessage(),
                previous: $e
            );
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
            throw new UnauthorizedException('Security constraints not met');
        }

        if (!$this->validator->verifySystemState()) {
            throw new SecurityException('System state invalid for operation');
        }
    }

    private function handleFailure(\Throwable $e, array $context, string $operationId): void
    {
        $this->auditLogger->logFailure($e, $context, $operationId, [
            'stack_trace' => $e->getTraceAsString(),
            'system_state' => $this->monitor->captureSystemState()
        ]);

        if ($this->isEmergencyProtocolRequired($e)) {
            $this->executeEmergencyProtocol($e, $context);
        }
    }

    private function isEmergencyProtocolRequired(\Throwable $e): bool
    {
        return $e instanceof SecurityException || 
               $e instanceof \PDOException ||
               $e->getCode() >= 500;
    }

    private function executeEmergencyProtocol(\Throwable $e, array $context): void
    {
        try {
            // Immediate system protection measures
            $this->monitor->triggerEmergencyProtocol();
            $this->auditLogger->logEmergencyProtocol($e, $context);
            
        } catch (\Exception $emergencyError) {
            // Last resort error handling
            error_log('CRITICAL: Emergency protocol failed: ' . $emergencyError->getMessage());
        }
    }
}
