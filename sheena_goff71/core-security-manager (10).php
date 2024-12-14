<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\{DB, Log, Cache};
use App\Core\Exceptions\{SecurityException, ValidationException};
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

    public function __construct(
        ValidationServiceInterface $validator,
        EncryptionService $encryption,
        AuditLoggerInterface $auditLogger,
        AccessControl $accessControl
    ) {
        $this->validator = $validator;
        $this->encryption = $encryption;
        $this->auditLogger = $auditLogger;
        $this->accessControl = $accessControl;
    }

    public function executeCriticalOperation(callable $operation, array $context): mixed
    {
        DB::beginTransaction();
        
        try {
            // Pre-execution validation
            $this->validateOperation($context);
            
            // Execute with monitoring
            $result = $this->monitorExecution($operation, $context);
            
            // Verify result integrity
            $this->verifyResult($result);
            
            DB::commit();
            
            $this->auditLogger->logSuccess($context, $result);
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFailure($e, $context);
            throw new SecurityException(
                'Critical operation failed: ' . $e->getMessage(), 
                previous: $e
            );
        }
    }

    private function validateOperation(array $context): void
    {
        // Validate input
        $this->validator->validate($context['input'] ?? [], $context['rules'] ?? []);

        // Check permissions
        if (!$this->accessControl->hasPermission($context['user'], $context['permission'])) {
            $this->auditLogger->logUnauthorizedAccess($context);
            throw new SecurityException('Insufficient permissions');
        }

        // Check rate limits
        if (!$this->accessControl->checkRateLimit($context['user'], $context['operation'])) {
            throw new SecurityException('Rate limit exceeded');
        }
    }

    private function monitorExecution(callable $operation, array $context): mixed
    {
        $startTime = microtime(true);
        
        try {
            return $operation();
        } finally {
            $duration = microtime(true) - $startTime;
            $this->auditLogger->logPerformance($context, $duration);
        }
    }

    private function verifyResult($result): void
    {
        if (!$this->validator->verifyResult($result)) {
            throw new ValidationException('Operation result validation failed');
        }

        if ($result instanceof HasIntegrity && !$this->encryption->verifyIntegrity($result)) {
            throw new SecurityException('Result integrity check failed');
        }
    }

    private function handleFailure(\Exception $e, array $context): void
    {
        $this->auditLogger->logFailure($e, $context, [
            'trace' => $e->getTraceAsString(),
            'state' => $this->captureSystemState()
        ]);

        // Alert monitoring systems
        event(new SecurityIncidentDetected($e, $context));
    }

    private function captureSystemState(): array
    {
        return [
            'memory' => memory_get_usage(true),
            'time' => microtime(true),
            'queries' => DB::getQueryLog(),
            'cache_stats' => Cache::getMemcachedStats()
        ];
    }
}
