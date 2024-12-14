<?php

namespace App\Core;

use Illuminate\Support\Facades\{DB, Cache, Log, View};
use App\Core\Exceptions\{SecurityException, ValidationException};

class CoreValidationService 
{
    public function validate($data, array $rules): bool 
    {
        foreach ($rules as $field => $validations) {
            if (!$this->validateField($data[$field] ?? null, $validations)) {
                throw new ValidationException("Validation failed for {$field}");
            }
        }
        return true;
    }

    private function validateField($value, array $validations): bool 
    {
        foreach ($validations as $validation => $params) {
            if (!$this->runValidation($validation, $value, $params)) {
                return false;
            }
        }
        return true;
    }

    private function runValidation(string $type, $value, $params): bool 
    {
        return match($type) {
            'required' => !empty($value),
            'string' => is_string($value),
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'min' => strlen($value) >= $params,
            'max' => strlen($value) <= $params,
            default => true
        };
    }
}

class CoreErrorHandler 
{
    private array $handlers = [];
    
    public function register(): void 
    {
        set_error_handler([$this, 'handleError']);
        set_exception_handler([$this, 'handleException']);
        register_shutdown_function([$this, 'handleShutdown']);
    }

    public function handleError($level, $message, $file, $line): void 
    {
        Log::error('System error', compact('level', 'message', 'file', 'line'));
        
        if (error_reporting() & $level) {
            throw new \ErrorException($message, 0, $level, $file, $line);
        }
    }

    public function handleException(\Throwable $e): void 
    {
        Log::error('Uncaught exception', [
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        
        if ($e instanceof SecurityException) {
            $this->handleSecurityException($e);
        }
    }

    private function handleSecurityException(SecurityException $e): void 
    {
        Cache::tags('security')->increment('security_incidents');
        Log::critical('Security incident', [
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    public function handleShutdown(): void 
    {
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR])) {
            $this->handleError(
                $error['type'],
                $error['message'],
                $error['file'],
                $error['line']
            );
        }
    }
}

trait SecurityChecks 
{
    private function validateOperation(string $operation, array $context): void 
    {
        if (!$this->validator->validate($context, $this->getOperationRules($operation))) {
            throw new SecurityException("Invalid operation: {$operation}");
        }
    }

    private function logOperation(string $operation, array $context, $result = null): void 
    {
        Log::info("Operation executed: {$operation}", [
            'context' => $context,
            'result' => $result ? 'success' : 'failure'
        ]);
    }

    private function getOperationRules(string $operation): array 
    {
        return [
            'auth' => ['required' => true, 'roles' => ['admin']],
            'content' => ['required' => true],
            'template' => ['required' => true, 'string' => true]
        ][$operation] ?? [];
    }
}

trait TransactionWrapper 
{
    protected function transact(callable $operation) 
    {
        DB::beginTransaction();
        try {
            $result = $operation();
            DB::commit();
            return $result;
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }
}

interface CriticalOperation 
{
    public function execute(array $context): mixed;
    public function validate(array $context): bool;
    public function rollback(): void;
}

abstract class BaseCriticalOperation implements CriticalOperation 
{
    use SecurityChecks, TransactionWrapper;

    protected CoreValidationService $validator;
    protected CoreErrorHandler $errorHandler;

    public function __construct(
        CoreValidationService $validator,
        CoreErrorHandler $errorHandler
    ) {
        $this->validator = $validator;
        $this->errorHandler = $errorHandler;
    }

    public final function execute(array $context): mixed 
    {
        $this->validateOperation(static::class, $context);
        return $this->transact(fn() => $this->executeOperation($context));
    }

    abstract protected function executeOperation(array $context): mixed;
}

