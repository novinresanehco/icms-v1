<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\DB;
use App\Core\Contracts\SecurityManagerInterface;
use App\Core\Exceptions\{SecurityException, ValidationException};

class SecurityManager implements SecurityManagerInterface 
{
    private $validator;
    private $encryption;
    private $audit;

    public function __construct(
        ValidationService $validator,
        EncryptionService $encryption,
        AuditService $audit
    ) {
        $this->validator = $validator;
        $this->encryption = $encryption;
        $this->audit = $audit;
    }

    public function executeCriticalOperation(callable $operation, array $context): mixed
    {
        DB::beginTransaction();
        
        try {
            // Pre-execution validation
            $this->validateOperation($context);
            
            // Execute with monitoring
            $startTime = microtime(true);
            $result = $operation();
            $executionTime = microtime(true) - $startTime;
            
            // Verify result
            $this->validateResult($result);
            
            // Log success and commit
            $this->audit->logSuccess($context, $result, $executionTime);
            DB::commit();
            
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFailure($e, $context);
            throw new SecurityException(
                'Operation failed: ' . $e->getMessage(),
                previous: $e
            );
        }
    }

    private function validateOperation(array $context): void 
    {
        if (!$this->validator->validateContext($context)) {
            throw new ValidationException('Invalid operation context');
        }

        if (!$this->validator->checkSecurityConstraints($context)) {
            throw new SecurityException('Security constraints not met');
        }
    }

    private function validateResult($result): void 
    {
        if (!$this->validator->validateResult($result)) {
            throw new ValidationException('Result validation failed');
        }
    }

    private function handleFailure(\Exception $e, array $context): void 
    {
        $this->audit->logFailure($e, $context);

        if ($this->isCriticalFailure($e)) {
            $this->notifyAdministrators($e, $context);
        }
    }

    private function isCriticalFailure(\Exception $e): bool 
    {
        return $e instanceof SecurityException || 
               $e instanceof ValidationException;
    }
}

class ValidationService
{
    private array $rules = [];
    private array $securityConstraints = [];

    public function validateContext(array $context): bool
    {
        foreach ($this->rules as $rule => $validator) {
            if (!$validator($context)) {
                return false;
            }
        }
        return true;
    }

    public function checkSecurityConstraints(array $context): bool
    {
        foreach ($this->securityConstraints as $constraint => $validator) {
            if (!$validator($context)) {
                return false;
            }
        }
        return true;
    }

    public function validateResult($result): bool
    {
        return !is_null($result);
    }
}

class EncryptionService 
{
    public function encrypt(string $data): string 
    {
        return openssl_encrypt(
            $data,
            'AES-256-GCM',
            config('app.key'),
            0,
            random_bytes(16)
        );
    }
    
    public function decrypt(string $encrypted): string 
    {
        return openssl_decrypt(
            $encrypted,
            'AES-256-GCM',
            config('app.key'),
            0,
            substr($encrypted, 0, 16)
        );
    }
}

class AuditService
{
    public function logSuccess(array $context, $result, float $executionTime): void 
    {
        Log::info('Operation completed successfully', [
            'context' => $context,
            'execution_time' => $executionTime,
            'timestamp' => now()
        ]);
    }

    public function logFailure(\Exception $e, array $context): void 
    {
        Log::error('Operation failed', [
            'context' => $context,
            'error' => $e->getMessage(),
            'stack_trace' => $e->getTraceAsString(),
            'timestamp' => now()
        ]);
    }
}
