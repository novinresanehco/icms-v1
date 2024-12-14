<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\Log;
use App\Core\Contracts\SecurityServiceInterface;
use App\Core\Exceptions\{
    SecurityViolationException,
    ValidationException,
    AuthorizationException
};

class CoreSecurityService implements SecurityServiceInterface 
{
    private ValidationService $validator;
    private EncryptionService $encryption;
    private AuditLogger $auditLogger;
    private AccessControl $accessControl;
    private MetricsCollector $metrics;

    public function __construct(
        ValidationService $validator,
        EncryptionService $encryption,
        AuditLogger $auditLogger,
        AccessControl $accessControl,
        MetricsCollector $metrics
    ) {
        $this->validator = $validator;
        $this->encryption = $encryption;
        $this->auditLogger = $auditLogger;
        $this->accessControl = $accessControl;
        $this->metrics = $metrics;
    }

    public function executeSecureOperation(callable $operation, array $context): mixed
    {
        $operationId = $this->generateOperationId();
        $startTime = microtime(true);

        try {
            // Pre-execution validation
            $this->validateOperationContext($context);
            $this->verifyAuthorization($context);
            
            // Execute within transaction
            DB::beginTransaction();
            
            $result = $this->executeWithMonitoring($operation, $operationId);
            
            // Verify result integrity
            $this->validateOperationResult($result);
            
            DB::commit();
            
            // Log successful operation
            $this->auditLogger->logSuccess($operationId, $context);
            
            return $result;

        } catch (\Throwable $e) {
            DB::rollBack();
            
            $this->handleSecurityFailure($e, $operationId, $context);
            
            throw new SecurityViolationException(
                'Security violation detected during operation execution',
                previous: $e
            );

        } finally {
            $this->recordMetrics($operationId, microtime(true) - $startTime);
            $this->cleanupOperation($operationId);
        }
    }

    private function validateOperationContext(array $context): void 
    {
        if (!$this->validator->validateContext($context)) {
            throw new ValidationException('Invalid operation context');
        }

        if (!$this->validator->checkSecurityConstraints($context)) {
            throw new SecurityViolationException('Security constraints not met');
        }
    }

    private function verifyAuthorization(array $context): void
    {
        if (!$this->accessControl->isAuthorized($context)) {
            $this->auditLogger->logUnauthorizedAccess($context);
            throw new AuthorizationException('Unauthorized operation attempt');
        }
    }

    private function executeWithMonitoring(callable $operation, string $operationId): mixed
    {
        return $this->metrics->trackOperation($operationId, function() use ($operation) {
            return $operation();
        });
    }

    private function validateOperationResult($result): void
    {
        if (!$this->validator->validateResult($result)) {
            throw new ValidationException('Operation result validation failed');
        }

        if (!$this->encryption->verifyIntegrity($result)) {
            throw new SecurityViolationException('Result integrity check failed');
        }
    }

    private function handleSecurityFailure(\Throwable $e, string $operationId, array $context): void
    {
        // Log detailed failure information
        $this->auditLogger->logSecurityFailure($operationId, $e, $context, [
            'stack_trace' => $e->getTraceAsString(),
            'system_state' => $this->metrics->captureSystemState()
        ]);

        // Notify security team
        $this->notifySecurityTeam($e, $operationId);

        // Update security metrics
        $this->metrics->incrementSecurityFailure(
            $context['operation_type'] ?? 'unknown',
            $e->getCode()
        );
    }

    private function generateOperationId(): string
    {
        return sprintf(
            '%s-%s',
            date('YmdHis'),
            bin2hex(random_bytes(8))
        );
    }

    private function recordMetrics(string $operationId, float $duration): void
    {
        $this->metrics->record([
            'operation_id' => $operationId,
            'duration' => $duration,
            'memory_peak' => memory_get_peak_usage(true),
            'timestamp' => time()
        ]);
    }

    private function cleanupOperation(string $operationId): void
    {
        try {
            $this->metrics->finalizeOperation($operationId);
        } catch (\Exception $e) {
            Log::error('Failed to cleanup operation', [
                'operation_id' => $operationId,
                'error' => $e->getMessage()
            ]);
        }
    }
}
