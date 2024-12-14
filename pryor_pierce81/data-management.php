<?php

namespace App\Core\Data;

use App\Core\Security\SecurityManager;
use App\Core\Validation\ValidationService;
use Illuminate\Support\Facades\{DB, Cache};

final class DataManager 
{
    private SecurityManager $security;
    private ValidationService $validator;
    private TransactionManager $transaction;
    private array $config;

    public function executeDataOperation(callable $operation, array $context): mixed 
    {
        $operationId = uniqid('data_', true);
        
        try {
            $this->validateDataContext($context);
            return $this->transaction->execute(function() use ($operation, $context) {
                $this->security->lockResource($context['resource']);
                $result = $operation();
                $this->validateResult($result);
                return $result;
            });
        } catch (\Throwable $e) {
            $this->handleDataFailure($e, $operationId);
            throw $e;
        }
    }

    private function validateDataContext(array $context): void 
    {
        if (!isset($context['resource'])) {
            throw new DataValidationException('Resource not specified');
        }

        if (!$this->validator->validateDataOperation($context)) {
            throw new DataValidationException('Invalid data operation');
        }
    }

    private function validateResult($result): void 
    {
        if (!$this->validator->validateDataResult($result)) {
            throw new DataValidationException('Invalid operation result');
        }
    }
}

final class TransactionManager 
{
    public function execute(callable $operation): mixed 
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

final class DataValidator 
{
    private array $rules;
    
    public function validateData(array $data, string $type): bool 
    {
        if (!isset($this->rules[$type])) {
            throw new DataValidationException("Unknown data type: $type");
        }

        foreach ($this->rules[$type] as $field => $rule) {
            if (!$this->validateField($data[$field] ?? null, $rule)) {
                return false;
            }
        }
        
        return true;
    }

    private function validateField($value, string $rule): bool 
    {
        return match($rule) {
            'required' => !is_null($value),
            'string' => is_string($value),
            'numeric' => is_numeric($value),
            'array' => is_array($value),
            default => $this->validateCustomRule($value, $rule)
        };
    }
}

final class CacheManager 
{
    private array $config;
    private string $prefix;

    public function remember(string $key, callable $callback, ?int $ttl = null): mixed 
    {
        $cacheKey = $this->prefix . $key;
        
        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        $value = $callback();
        Cache::put($cacheKey, $value, $ttl ?? $this->config['default_ttl']);
        return $value;
    }

    public function invalidate(array $tags): void 
    {
        foreach ($tags as $tag) {
            Cache::tags($tag)->flush();
        }
    }
}

class DataValidationException extends \Exception {}
