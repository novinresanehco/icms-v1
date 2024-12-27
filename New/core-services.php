<?php

namespace App\Core\Services;

class ValidationService implements ValidationInterface 
{
    private array $rules = [];
    private CacheManager $cache;

    public function validateOperation(Operation $operation): bool
    {
        $key = "validation:{$operation->getType()}:{$operation->getId()}";
        
        return $this->cache->remember($key, function() use ($operation) {
            foreach ($this->rules[$operation->getType()] ?? [] as $rule) {
                if (!$rule->validate($operation)) {
                    throw new ValidationException($rule->getMessage());
                }
            }
            return true;
        }, 300);
    }

    public function validateResult(OperationResult $result): bool
    {
        $key = "result_validation:{$result->getType()}:{$result->getId()}";
        
        return $this->cache->remember($key, function() use ($result) {
            return $this->validateResultData($result);
        }, 300);
    }

    private function validateResultData(OperationResult $result): bool
    {
        $data = $result->getData();
        $rules = $this->rules[$result->getType()] ?? [];

        foreach ($rules as $field => $rule) {
            if (!$this->validateField($data[$field], $rule)) {
                return false;
            }
        }
        return true;
    }
}

class CacheService implements CacheInterface
{
    private CacheStore $store;
    private EncryptionService $encryption;
    private int $defaultTtl = 3600;

    public function remember(string $key, callable $callback, ?int $ttl = null): mixed
    {
        $ttl = $ttl ?? $this->defaultTtl;
        $value = $this->get($key);

        if ($value !== null) {
            return $value;
        }

        $value = $callback();
        $this->set($key, $value, $ttl);
        
        return $value;
    }

    public function get(string $key): mixed
    {
        $value = $this->store->get($key);
        
        if ($value === null) {
            return null;
        }

        return $this->encryption->decrypt($value);
    }

    public function set(string $key, mixed $value, int $ttl): void
    {
        $encrypted = $this->encryption->encrypt(serialize($value));
        $this->store->put($key, $encrypted, $ttl);
    }

    public function invalidate(string $key): void
    {
        $this->store->forget($key);
    }
}

class AuditService implements AuditInterface
{
    private LoggerInterface $logger;
    private CacheManager $cache;

    public function logOperation(Operation $operation): void
    {
        $this->logger->info('Operation executed', [
            'type' => $operation->getType(),
            'user_id' => $operation->getUserId(),
            'timestamp' => time(),
            'data' => $this->sanitizeData($operation->getData())
        ]);
    }

    public function logFailure(\Exception $e, Operation $operation): void
    {
        $this->logger->error('Operation failed', [
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'operation' => $operation->getType(),
            'user_id' => $operation->getUserId(),
            'timestamp' => time()
        ]);

        $this->cacheFailure($operation);
    }

    private function cacheFailure(Operation $operation): void
    {
        $key = "failures:{$operation->getType()}:{$operation->getUserId()}";
        $failures = (int)$this->cache->get($key, 0);
        $this->cache->set($key, $failures + 1, 3600);
    }

    private function sanitizeData(array $data): array
    {
        return array_map(function($value) {
            if (is_array($value)) {
                return $this->sanitizeData($value);
            }
            return $this->maskSensitiveData($value);
        }, $data);
    }

    private function maskSensitiveData(mixed $value): mixed
    {
        if (is_string($value) && strlen($value) > 4) {
            return substr($value, 0, 4) . str_repeat('*', strlen($value) - 4);
        }
        return $value;
    }
}
