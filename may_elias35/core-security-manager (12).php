<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\DB;
use App\Core\Exceptions\SecurityException;
use App\Core\Interfaces\{
    SecurityManagerInterface,
    ValidationServiceInterface,
    AuditLoggerInterface
};

class SecurityManager implements SecurityManagerInterface
{
    private ValidationServiceInterface $validator;
    private EncryptionService $encryption;
    private AuditLoggerInterface $auditLogger;
    private AccessControl $accessControl;
    private MetricsCollector $metrics;

    public function __construct(
        ValidationServiceInterface $validator,
        EncryptionService $encryption,
        AuditLoggerInterface $auditLogger,
        AccessControl $accessControl,
        MetricsCollector $metrics
    ) {
        $this->validator = $validator;
        $this->encryption = $encryption;
        $this->auditLogger = $auditLogger;
        $this->accessControl = $accessControl;
        $this->metrics = $metrics;
    }

    public function executeCriticalOperation(CriticalOperation $operation): OperationResult
    {
        DB::beginTransaction();
        $startTime = microtime(true);

        try {
            // Pre-execution validation
            $this->validateOperation($operation);
            
            // Execute with monitoring
            $result = $this->executeWithProtection($operation);

            // Verify result integrity
            $this->verifyResult($result);

            DB::commit();
            $this->logSuccess($operation, $result);
            
            return $result;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFailure($operation, $e);
            throw new SecurityException('Critical operation failed', 0, $e);
        } finally {
            $this->recordMetrics($operation, microtime(true) - $startTime);
        }
    }

    private function validateOperation(CriticalOperation $operation): void
    {
        // Validate input data
        if (!$this->validator->validateInput($operation->getData())) {
            throw new ValidationException('Invalid operation input');
        }

        // Check permissions
        if (!$this->accessControl->hasPermission($operation->getRequiredPermissions())) {
            throw new UnauthorizedException('Insufficient permissions');
        }

        // Verify rate limits 
        if (!$this->accessControl->checkRateLimit($operation->getRateLimitKey())) {
            throw new RateLimitException('Rate limit exceeded');
        }
    }

    private function executeWithProtection(CriticalOperation $operation): OperationResult
    {
        $monitor = new OperationMonitor($operation);

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
        if (!$this->validator->verifyResult($result)) {
            throw new ValidationException('Invalid operation result');
        }

        if (!$this->validator->verifyBusinessRules($result)) {
            throw new BusinessRuleException('Business rule validation failed');
        }
    }

    private function logSuccess(CriticalOperation $operation, OperationResult $result): void
    {
        $this->auditLogger->logSuccess($operation, $result);
    }

    private function handleFailure(CriticalOperation $operation, \Exception $e): void
    {
        $this->auditLogger->logFailure($operation, $e);
        $this->metrics->incrementFailureCount($operation->getType());
    }

    private function recordMetrics(CriticalOperation $operation, float $duration): void
    {
        $this->metrics->record([
            'operation_type' => $operation->getType(),
            'duration' => $duration,
            'memory_usage' => memory_get_peak_usage(true),
            'timestamp' => time()
        ]);
    }
}
