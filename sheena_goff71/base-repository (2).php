<?php

namespace App\Core\Repository;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Core\Validation\ValidationService;
use App\Core\Security\SecurityService;
use App\Core\Exceptions\{
    ValidationException,
    SecurityException,
    RepositoryException
};

/**
 * Base repository with integrated security, validation and caching
 */
abstract class BaseRepository
{
    protected Model $model;
    protected ValidationService $validator;
    protected SecurityService $security;
    
    public function __construct(
        Model $model,
        ValidationService $validator,
        SecurityService $security
    ) {
        $this->model = $model;
        $this->validator = $validator;
        $this->security = $security;
    }

    /**
     * Find record by ID with security checks and caching
     */
    public function find(int $id): ?Model
    {
        return $this->executeSecurely(function() use ($id) {
            return Cache::remember(
                $this->getCacheKey('find', $id),
                $this->getCacheDuration(),
                fn() => $this->model->find($id)
            );
        });
    }

    /**
     * Create new record with validation and security
     */
    public function create(array $data): Model 
    {
        return $this->executeSecurely(function() use ($data) {
            $validated = $this->validator->validate($data, $this->getCreateRules());
            
            $model = $this->model->create($validated);
            
            $this->clearModelCache();
            
            return $model;
        });
    }

    /**
     * Update record with validation and security
     */
    public function update(int $id, array $data): Model
    {
        return $this->executeSecurely(function() use ($id, $data) {
            $model = $this->findOrFail($id);
            
            $validated = $this->validator->validate($data, $this->getUpdateRules($id));
            
            $model->update($validated);
            
            $this->clearModelCache();
            
            return $model->fresh();
        });
    }

    /**
     * Delete record with security checks
     */
    public function delete(int $id): bool
    {
        return $this->executeSecurely(function() use ($id) {
            $model = $this->findOrFail($id);
            
            $result = $model->delete();
            
            $this->clearModelCache();
            
            return $result;
        });
    }

    /**
     * Execute operation with security and error handling
     */
    protected function executeSecurely(callable $operation)
    {
        try {
            $this->security->validateRequest();
            
            return $operation();
            
        } catch (ValidationException $e) {
            Log::error('Validation failed', [
                'exception' => $e,
                'repository' => static::class
            ]);
            throw $e;
            
        } catch (SecurityException $e) {
            Log::error('Security check failed', [
                'exception' => $e,
                'repository' => static::class
            ]);
            throw $e;
            
        } catch (\Exception $e) {
            Log::error('Repository operation failed', [
                'exception' => $e,
                'repository' => static::class
            ]);
            throw new RepositoryException(
                'Repository operation failed',
                previous: $e
            );
        }
    }

    /**
     * Get cache key for operation
     */
    protected function getCacheKey(string $operation, ...$params): string
    {
        return sprintf(
            '%s:%s:%s',
            static::class,
            $operation,
            implode(':', $params)
        );
    }

    /**
     * Clear all cache for this model
     */
    protected function clearModelCache(): void
    {
        // Implementation depends on caching strategy
        Cache::tags($this->model->getTable())->flush();
    }

    /**
     * Get cache duration in seconds
     */
    protected function getCacheDuration(): int
    {
        return config('cache.ttl', 3600);
    }

    /**
     * Get validation rules for create
     */
    abstract protected function getCreateRules(): array;

    /**
     * Get validation rules for update
     */
    abstract protected function getUpdateRules(int $id): array;
}
