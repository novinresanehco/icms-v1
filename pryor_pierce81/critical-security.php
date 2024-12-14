<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\{DB, Log, Cache};
use App\Core\Contracts\{SecurityInterface, ValidationInterface};

final class CriticalSecurityManager implements SecurityInterface
{
    private ValidationService $validator;
    private AccessControl $access;
    private AuditLogger $audit;
    private array $config;

    public function executeSecure(callable $operation, array $context): mixed
    {
        $operationId = uniqid('op_', true);
        $this->audit->logOperationStart($operationId, $context);
        
        DB::beginTransaction();

        try {
            $this->validateSecurity($context);
            $result = $operation();
            $this->validateResult($result);
            
            DB::commit();
            $this->audit->logSuccess($operationId);
            
            return $result;
            
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->handleSecurityFailure($e, $operationId);
            throw $e;
        }
    }

    private function validateSecurity(array $context): void
    {
        if (!$this->access->validatePermissions($context)) {
            throw new SecurityException('Access denied');
        }

        if (!$this->validator->validateRequest($context)) {
            throw new ValidationException('Invalid request');
        }
    }

    private function validateResult($result): void
    {
        if (!$this->validator->validateResponse($result)) {
            throw new ValidationException('Invalid result');
        }
    }

    private function handleSecurityFailure(\Throwable $e, string $operationId): void
    {
        $this->audit->logFailure($operationId, $e);
        $this->access->lockAccount($e->getSubject());
        Log::critical('Security failure', [
            'operation' => $operationId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}

final class AccessControl
{
    private PermissionRegistry $permissions;
    private SessionManager $sessions;
    private int $maxAttempts = 3;

    public function validatePermissions(array $context): bool
    {
        $user = $this->sessions->getCurrentUser();
        $permission = $context['permission'] ?? null;

        if (!$permission) {
            throw new SecurityException('Permission not specified');
        }

        if ($this->isLocked($user)) {
            throw new SecurityException('Account locked');
        }

        return $this->permissions->hasPermission($user, $permission);
    }

    public function lockAccount(string $subject): void
    {
        Cache::put("security.lock.$subject", true, 3600);
    }

    private function isLocked(string $subject): bool
    {
        $attempts = Cache::get("security.attempts.$subject", 0);
        return $attempts >= $this->maxAttempts;
    }
}

final class ValidationService implements ValidationInterface
{
    private array $rules;

    public function validateRequest(array $context): bool
    {
        foreach ($this->rules as $field => $rule) {
            if (!$this->validateField($context[$field] ?? null, $rule)) {
                return false;
            }
        }
        return true;
    }

    public function validateResponse($result): bool
    {
        return match(true) {
            is_null($result) => false,
            is_array($result) => $this->validateArrayResponse($result),
            is_object($result) => $this->validateObjectResponse($result),
            default => true
        };
    }

    private function validateField($value, $rule): bool
    {
        return match($rule) {
            'required' => !is_null($value),
            'string' => is_string($value),
            'array' => is_array($value),
            'numeric' => is_numeric($value),
            default => $this->validateCustomRule($value, $rule)
        };
    }
}

final class AuditLogger
{
    private LogManager $logger;

    public function logOperationStart(string $id, array $context): void
    {
        $this->logger->info('Operation started', [
            'id' => $id,
            'context' => $this->sanitizeContext($context),
            'timestamp' => microtime(true)
        ]);
    }

    public function logSuccess(string $id): void
    {
        $this->logger->info('Operation successful', [
            'id' => $id,
            'timestamp' => microtime(true)
        ]);
    }

    public function logFailure(string $id, \Throwable $e): void
    {
        $this->logger->error('Operation failed', [
            'id' => $id,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'timestamp' => microtime(true)
        ]);
    }

    private function sanitizeContext(array $context): array
    {
        unset($context['password'], $context['token']);
        return $context;
    }
}

interface LogManager
{
    public function info(string $message, array $context = []): void;
    public function error(string $message, array $context = []): void;
    public function critical(string $message, array $context = []): void;
}

class SecurityException extends \Exception {}
class ValidationException extends \Exception {}
