<?php

namespace App\Core\Security;

use App\Core\Exceptions\SecurityException;
use App\Core\Interfaces\SecurityManagerInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SecurityManager implements SecurityManagerInterface
{
    private $validator;
    private $encryption;
    private $auditLogger;

    public function __construct(
        ValidationService $validator,
        EncryptionService $encryption,
        AuditLogger $auditLogger
    ) {
        $this->validator = $validator;
        $this->encryption = $encryption;
        $this->auditLogger = $auditLogger;
    }

    public function executeCriticalOperation(callable $operation, array $context): mixed
    {
        DB::beginTransaction();
        
        try {
            // Pre-execution validation
            $this->validateContext($context);
            
            // Execute with monitoring
            $startTime = microtime(true);
            $result = $operation();
            
            // Verify result
            $this->validateResult($result);
            
            // Log success and commit
            $this->logSuccess($context, $result, microtime(true) - $startTime);
            DB::commit();
            
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFailure($e, $context);
            throw new SecurityException('Operation failed: ' . $e->getMessage(), 0, $e);
        }
    }

    private function validateContext(array $context): void
    {
        if (!$this->validator->validateContext($context)) {
            throw new SecurityException('Invalid operation context');
        }
    }

    private function validateResult($result): void
    {
        if (!$this->validator->validateResult($result)) {
            throw new SecurityException('Operation result validation failed');
        }
    }

    private function handleFailure(\Exception $e, array $context): void
    {
        // Log failure with full context
        $this->auditLogger->logFailure(
            $e,
            $context,
            [
                'trace' => $e->getTraceAsString(),
                'system_state' => $this->captureSystemState()
            ]
        );
    }

    private function logSuccess(array $context, $result, float $executionTime): void
    {
        $this->auditLogger->logSuccess($context, [
            'execution_time' => $executionTime,
            'memory_usage' => memory_get_peak_usage(true),
            'result_hash' => $this->encryption->hash($result)
        ]);
    }

    private function captureSystemState(): array
    {
        return [
            'memory' => memory_get_usage(true),
            'time' => microtime(true),
            'load' => sys_getloadavg()
        ];
    }
}

class ValidationService
{
    private array $rules;

    public function validateContext(array $context): bool
    {
        try {
            foreach ($this->rules as $rule => $validator) {
                if (!$validator($context)) {
                    Log::warning('Context validation failed', ['rule' => $rule]);
                    return false;
                }
            }
            return true;
        } catch (\Exception $e) {
            Log::error('Validation error', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function validateResult($result): bool
    {
        // Implement critical result validation
        return true;
    }
}

class AuditLogger
{
    public function logFailure(\Exception $e, array $context, array $extras = []): void
    {
        Log::error('Operation failed', [
            'exception' => $e->getMessage(),
            'context' => $context,
            'extras' => $extras
        ]);
    }

    public function logSuccess(array $context, array $metrics): void
    {
        Log::info('Operation successful', [
            'context' => $context,
            'metrics' => $metrics
        ]);
    }
}
