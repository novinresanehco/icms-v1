<?php

namespace App\Core\Security;

use App\Core\Auth\AuthManagerInterface;
use App\Core\Exceptions\SecurityException;
use App\Core\Monitoring\MetricsCollector;
use App\Core\Security\Validators\SecurityValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class SecurityManager implements SecurityManagerInterface
{
    private AuthManagerInterface $authManager;
    private SecurityValidator $validator;
    private MetricsCollector $metrics;
    private AuditLogger $auditLogger;

    public function __construct(
        AuthManagerInterface $authManager,
        SecurityValidator $validator,
        MetricsCollector $metrics,
        AuditLogger $auditLogger
    ) {
        $this->authManager = $authManager;
        $this->validator = $validator;
        $this->metrics = $metrics;
        $this->auditLogger = $auditLogger;
    }

    public function executeCriticalOperation(callable $operation, array $context): mixed
    {
        $startTime = microtime(true);

        try {
            $this->validateOperation($context);
            
            DB::beginTransaction();
            
            $result = $this->executeWithProtection($operation, $context);
            
            $this->validateResult($result);
            
            DB::commit();
            
            $this->recordSuccess($context, $startTime);
            
            return $result;

        } catch (Throwable $e) {
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
        if (!$this->authManager->isAuthenticated()) {
            throw new SecurityException('Not authenticated');
        }

        if (!$this->validator->validateContext($context)) {
            throw new SecurityException('Invalid operation context');
        }

        if (!$this->authManager->hasPermission($context['permission'] ?? '')) {
            throw new SecurityException('Permission denied');
        }
    }

    private function executeWithProtection(callable $operation, array $context): mixed
    {
        $this->auditLogger->logOperationStart($context);

        try {
            return $operation();
        } catch (Throwable $e) {
            $this->auditLogger->logOperationFailure($context, $e);
            throw $e;
        }
    }

    private function validateResult($result): void 
    {
        if (!$this->validator->validateResult($result)) {
            throw new SecurityException('Operation result validation failed');
        }
    }

    private function recordSuccess(array $context, float $startTime): void
    {
        $duration = microtime(true) - $startTime;
        
        $this->metrics->recordOperationDuration($context['operation'] ?? 'unknown', $duration);
        $this->auditLogger->logOperationSuccess($context);
    }

    private function handleFailure(Throwable $e, array $context): void
    {
        Log::error('Security operation failed', [
            'exception' => $e->getMessage(),
            'context' => $context,
            'trace' => $e->getTraceAsString()
        ]);

        $this->auditLogger->logOperationFailure($context, $e);
        $this->metrics->incrementFailureCount($context['operation'] ?? 'unknown');
    }
}
