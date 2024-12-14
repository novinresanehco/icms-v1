<?php

namespace App\Core\Services;

use Illuminate\Support\Facades\{DB, Cache, Log};
use App\Core\Security\SecurityManager;
use App\Core\Cache\CacheManager;
use App\Core\Validation\ValidationService;
use App\Core\Events\EventManager;

abstract class BaseService
{
    protected SecurityManager $security;
    protected CacheManager $cache;
    protected ValidationService $validator;
    protected EventManager $events;
    protected array $config;

    public function __construct(
        SecurityManager $security,
        CacheManager $cache,
        ValidationService $validator,
        EventManager $events,
        array $config
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->validator = $validator;
        $this->events = $events;
        $this->config = $config;
    }

    protected function executeOperation(callable $operation, array $metadata = []): mixed
    {
        return $this->security->executeCriticalOperation(function() use ($operation, $metadata) {
            return DB::transaction(function() use ($operation, $metadata) {
                try {
                    $this->validateOperation($metadata);
                    $this->recordOperationStart($metadata);
                    
                    $result = $operation();
                    
                    $this->validateResult($result);
                    $this->recordOperationSuccess($metadata);
                    
                    return $result;
                    
                } catch (\Exception $e) {
                    $this->handleOperationFailure($e, $metadata);
                    throw $e;
                }
            });
        });
    }

    protected function executeWithCache(string $key, callable $operation, ?int $ttl = null): mixed
    {
        return $this->cache->remember($key, function() use ($operation) {
            return $this->executeOperation($operation);
        }, $ttl ?? $this->config['cache_ttl']);
    }

    protected function validateOperation(array $metadata): void
    {
        $rules = $this->getValidationRules($metadata);
        $this->validator->validate($metadata, $rules);
    }

    protected function validateResult($result): void
    {
        if ($result instanceof ValidatableInterface) {
            $rules = $this->getResultValidationRules();
            $this->validator->validate($result->toArray(), $rules);
        }
    }

    protected function recordOperationStart(array $metadata): void
    {
        $this->events->dispatch('operation.started', [
            'service' => static::class,
            'metadata' => $metadata,
            'timestamp' => now()
        ]);
    }

    protected function recordOperationSuccess(array $metadata): void
    {
        $this->events->dispatch('operation.completed', [
            'service' => static::class,
            'metadata' => $metadata,
            'duration' => $this->getOperationDuration(),
            'timestamp' => now()
        ]);
    }

    protected function handleOperationFailure(\Exception $e, array $metadata): void
    {
        $this->events->dispatch('operation.failed', [
            'service' => static::class,
            'metadata' => $metadata,
            'error' => [
                'type' => get_class($e),
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ],
            'duration' => $this->getOperationDuration(),
            'timestamp' => now()
        ]);

        Log::error('Service operation failed', [
            'service' => static::class,
            'metadata' => $metadata,
            'exception' => $e
        ]);
    }

    protected function invalidateCache(array $tags = []): void
    {
        $this->cache->tags($tags)->flush();
    }

    protected function getCacheKey(string $operation, ...$params): string
    {
        return static::class . '.' . $operation . '.' . md5(serialize($params));
    }

    protected function getOperationDuration(): float
    {
        return microtime(true) - LARAVEL_START;
    }

    protected function dispatchAsync(string $event, array $data = []): void
    {
        $this->events->dispatch($event, array_merge($data, [
            'service' => static::class,
            'timestamp' => now()
        ]));
    }

    protected function wrapInRetryBlock(callable $operation, int $maxRetries = 3): mixed
    {
        $attempt = 1;
        
        while (true) {
            try {
                return $operation();
            } catch (\Exception $e) {
                if ($attempt >= $maxRetries) {
                    throw $e;
                }
                
                $this->handleRetryFailure($e, $attempt);
                $attempt++;
                
                sleep($this->getRetryDelay($attempt));
            }
        }
    }

    protected function handleRetryFailure(\Exception $e, int $attempt): void
    {
        Log::warning('Operation failed, retrying...', [
            'service' => static::class,
            'attempt' => $attempt,
            'exception' => $e
        ]);
    }

    protected function getRetryDelay(int $attempt): int
    {
        return min(
            $this->config['max_retry_delay'],
            $this->config['base_retry_delay'] * (2 ** ($attempt - 1))
        );
    }

    abstract protected function getValidationRules(array $metadata): array;
    
    abstract protected function getResultValidationRules(): array;
}
