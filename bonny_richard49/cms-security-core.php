// src/Core/Security/SecurityManager.php
<?php

namespace App\Core\Security;

use App\Core\Interfaces\SecurityManagerInterface;
use Illuminate\Support\Facades\{DB, Cache, Log};

class SecurityManager implements SecurityManagerInterface 
{
    private EncryptionService $encryption;
    private ValidationService $validator;
    private AuditService $audit;
    private PermissionManager $permissions;

    public function executeCriticalOperation(callable $operation, array $context = []): mixed
    {
        DB::beginTransaction();
        $operationId = $this->audit->startOperation();
        
        try {
            // Pre-execution validation
            $this->validateContext($context);
            $this->validatePermissions($context);
            
            // Execute with monitoring
            $result = $this->executeWithMonitoring($operation, $operationId);
            
            // Post-execution validation
            $this->validateResult($result);
            
            DB::commit();
            $this->audit->completeOperation($operationId);
            
            return $result;
            
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->audit->failOperation($operationId, $e);
            $this->handleFailure($e, $context);
            throw $e;
        }
    }

    private function validateContext(array $context): void 
    {
        if (!$this->validator->validateOperationContext($context)) {
            throw new SecurityValidationException('Invalid operation context');
        }
    }

    private function validatePermissions(array $context): void
    {
        if (!$this->permissions->checkPermissions($context)) {
            throw new PermissionDeniedException('Insufficient permissions');
        }
    }

    private function executeWithMonitoring(callable $operation, string $operationId): mixed
    {
        return Monitor::track($operationId, $operation);
    }

    private function validateResult($result): void
    {
        if (!$this->validator->validateOperationResult($result)) {
            throw new ValidationException('Operation result validation failed');
        }
    }

    private function handleFailure(\Throwable $e, array $context): void
    {
        Log::critical('Security operation failed', [
            'exception' => $e->getMessage(),
            'context' => $context,
            'trace' => $e->getTraceAsString()
        ]);
    }
}

// src/Core/Security/ValidationService.php 
class ValidationService 
{
    public function validateOperationContext(array $context): bool 
    {
        foreach ($context as $key => $value) {
            if (!$this->validateField($key, $value)) {
                return false;
            }
        }
        return true;
    }

    public function validateOperationResult($result): bool 
    {
        if ($result instanceof Model) {
            return $this->validateModel($result);
        }
        return $this->validateGenericResult($result);
    }

    private function validateField(string $key, $value): bool 
    {
        return match($key) {
            'user_id' => $this->validateUserId($value),
            'action' => $this->validateAction($value),
            'data' => $this->validateData($value),
            default => true
        };
    }
}

// src/Core/Security/PermissionManager.php
class PermissionManager 
{
    public function checkPermissions(array $context): bool 
    {
        $user = $context['user'] ?? auth()->user();
        $action = $context['action'];
        $resource = $context['resource'] ?? null;

        return Cache::remember(
            "permissions.{$user->id}.{$action}",
            now()->addMinutes(60),
            fn() => $this->verifyPermissions($user, $action, $resource)
        );
    }

    private function verifyPermissions($user, $action, $resource = null): bool 
    {
        if ($resource) {
            return $user->can($action, $resource);
        }
        return $user->can($action);
    }
}

// src/Core/Security/AuditService.php
class AuditService 
{
    public function startOperation(): string 
    {
        $operationId = uniqid('op_', true);
        $this->logOperationStart($operationId);
        return $operationId;
    }

    public function completeOperation(string $operationId): void 
    {
        $this->logOperationComplete($operationId);
    }

    public function failOperation(string $operationId, \Throwable $e): void 
    {
        $this->logOperationFailure($operationId, $e);
    }

    private function logOperationStart(string $operationId): void 
    {
        Log::info('Operation started', [
            'operation_id' => $operationId,
            'timestamp' => now(),
            'user' => auth()->id()
        ]);
    }
}