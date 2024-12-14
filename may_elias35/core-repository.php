<?php

namespace App\Core\Repository;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Core\Security\ValidationService;
use App\Core\Security\SecurityManager;
use App\Core\Contracts\RepositoryInterface;
use App\Core\Exceptions\{
    ValidationException,
    SecurityException,
    RepositoryException
};

abstract class BaseRepository implements RepositoryInterface
{
    protected Model $model;
    protected ValidationService $validator;
    protected SecurityManager $security;
    protected array $defaultCriteria = [];
    protected int $cacheTimeout;

    public function __construct(
        Model $model,
        ValidationService $validator, 
        SecurityManager $security
    ) {
        $this->model = $model;
        $this->validator = $validator;
        $this->security = $security;
        $this->cacheTimeout = config('cache.ttl', 3600);
        $this->boot();
    }

    /**
     * Execute repository operation with full security controls
     */
    protected function executeSecure(string $operation, callable $callback, array $params = [])
    {
        $context = $this->createSecurityContext($operation, $params);
        
        try {
            $this->security->validateOperation($context);
            
            $data = DB::transaction(function () use ($callback) {
                return $callback();
            });
            
            $this->security->verifyResult($data);
            $this->audit($operation, $params, $data);
            
            return $data;
            
        } catch (\Exception $e) {
            $this->handleException($e, $operation, $params);
            throw $e;
        }
    }

    /**
     * Find record by ID with security validation and caching
     */
    public function find(int $id, array $columns = ['*']): ?Model 
    {
        $cacheKey = $this->getCacheKey('find', $id);
        
        return Cache::remember($cacheKey, $this->cacheTimeout, function () use ($id, $columns) {
            return $this->executeSecure('find', function () use ($id, $columns) {
                return $this->model->find($id, $columns);
            }, ['id' => $id]);
        });
    }

    /**
     * Create new record with validation and security checks
     */
    public function create(array $data): Model
    {
        $validated = $this->validator->validate($data, $this->getCreateRules());
        
        return $this->executeSecure('create', function () use ($validated) {
            $model = $this->model->create($validated);
            $this->clearCache();
            return $model;
        }, $validated);
    }

    /**
     * Update record with validation and security checks
     */
    public function update(int $id, array $data): Model
    {
        $validated = $this->validator->validate($data, $this->getUpdateRules($id));
        
        return $this->executeSecure('update', function () use ($id, $validated) {
            $model = $this->find($id);
            
            if (!$model) {
                throw new RepositoryException("Record not found: {$id}");
            }
            
            $model->update($validated);
            $this->clearCache();
            return $model;
        }, ['id' => $id] + $validated);
    }

    /**
     * Delete record with security checks
     */
    public function delete(int $id): bool
    {
        return $this->executeSecure('delete', function () use ($id) {
            $model = $this->find($id);
            
            if (!$model) {
                throw new RepositoryException("Record not found: {$id}");
            }
            
            $result = $model->delete();
            $this->clearCache();
            return $result;
        }, ['id' => $id]);
    }

    /**
     * Clear cache for this repository
     */
    protected function clearCache(): void
    {
        $tag = $this->getCacheTag();
        Cache::tags($tag)->flush();
    }

    /**
     * Generate cache key for operation
     */
    protected function getCacheKey(string $operation, ...$params): string
    {
        return sprintf(
            '%s:%s:%s',
            $this->getCacheTag(),
            $operation,
            md5(serialize($params))
        );
    }

    /**
     * Get cache tag for this repository
     */
    protected function getCacheTag(): string
    {
        return str_replace('\\', '.', get_class($this->model));
    }

    /**
     * Create security context for operation
     */
    protected function createSecurityContext(string $operation, array $params): array
    {
        return [
            'operation' => $operation,
            'repository' => get_class($this),
            'model' => get_class($this->model),
            'params' => $params,
            'timestamp' => now(),
            'user_id' => auth()->id()
        ];
    }

    /**
     * Handle repository exception with logging
     */
    protected function handleException(\Exception $e, string $operation, array $params): void
    {
        Log::error('Repository operation failed', [
            'operation' => $operation,
            'repository' => get_class($this),
            'params' => $params,
            'exception' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    /**
     * Log audit entry for operation
     */
    protected function audit(string $operation, array $params, $result): void
    {
        Log::info('Repository operation completed', [
            'operation' => $operation,
            'repository' => get_class($this),
            'params' => $params,
            'result' => $result instanceof Model ? $result->id : $result
        ]);
    }

    /**
     * Initialize repository settings
     */
    protected function boot(): void
    {
        // Override in child classes if needed
    }

    /**
     * Get validation rules for create operation
     */
    abstract protected function getCreateRules(): array;

    /**
     * Get validation rules for update operation
     */
    abstract protected function getUpdateRules(int $id): array;
}
