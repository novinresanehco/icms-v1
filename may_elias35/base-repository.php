<?php

namespace App\Core\Repository;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Core\Interfaces\RepositoryInterface;
use App\Core\Security\ValidationService;
use App\Core\Exceptions\{
    ValidationException,
    SecurityException,
    DatabaseException
};

abstract class BaseRepository implements RepositoryInterface
{
    protected Model $model;
    protected ValidationService $validator;
    protected string $cachePrefix;
    protected int $cacheTTL = 3600; // 1 hour default

    public function __construct(Model $model, ValidationService $validator)
    {
        $this->model = $model;
        $this->validator = $validator;
        $this->cachePrefix = static::class;
    }

    /**
     * Find record by ID with caching and validation
     */
    public function find(int $id): ?Model
    {
        try {
            return Cache::remember(
                $this->getCacheKey('find', $id),
                $this->cacheTTL,
                function() use ($id) {
                    $result = $this->model->find($id);
                    
                    if ($result && !$this->validator->validateModel($result)) {
                        throw new ValidationException('Model validation failed');
                    }
                    
                    return $result;
                }
            );
        } catch (\Exception $e) {
            Log::error('Repository find error', [
                'id' => $id,
                'exception' => $e->getMessage()
            ]);
            throw new DatabaseException('Error retrieving record', 0, $e);
        }
    }

    /**
     * Create new record with validation
     */
    public function create(array $data): Model 
    {
        try {
            // Validate input data
            $validated = $this->validator->validate($data);

            // Create record within transaction
            return DB::transaction(function() use ($validated) {
                $model = $this->model->create($validated);
                
                // Clear relevant caches
                $this->clearModelCache();
                
                // Log creation
                Log::info('Record created', [
                    'model' => get_class($this->model),
                    'id' => $model->id
                ]);
                
                return $model;
            });
            
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Repository create error', [
                'data' => $data,
                'exception' => $e->getMessage()
            ]);
            throw new DatabaseException('Error creating record', 0, $e);
        }
    }

    /**
     * Update record with validation
     */
    public function update(int $id, array $data): Model
    {
        try {
            // Find existing record
            $model = $this->find($id);
            if (!$model) {
                throw new DatabaseException('Record not found');
            }

            // Validate update data
            $validated = $this->validator->validateUpdate($data, $model);

            // Update within transaction
            return DB::transaction(function() use ($model, $validated) {
                $model->update($validated);
                
                // Clear caches
                $this->clearModelCache($model->id);
                
                // Log update
                Log::info('Record updated', [
                    'model' => get_class($this->model),
                    'id' => $model->id
                ]);
                
                return $model->fresh();
            });
            
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Repository update error', [
                'id' => $id,
                'data' => $data,
                'exception' => $e->getMessage()
            ]);
            throw new DatabaseException('Error updating record', 0, $e);
        }
    }

    /**
     * Delete record with validation
     */
    public function delete(int $id): bool
    {
        try {
            return DB::transaction(function() use ($id) {
                // Find and validate deletion
                $model = $this->find($id);
                if (!$model) {
                    throw new DatabaseException('Record not found');
                }

                if (!$this->validator->validateDeletion($model)) {
                    throw new ValidationException('Cannot delete record');
                }

                // Perform deletion
                $deleted = $model->delete();
                
                // Clear caches
                $this->clearModelCache($id);
                
                // Log deletion
                Log::info('Record deleted', [
                    'model' => get_class($this->model),
                    'id' => $id
                ]);
                
                return $deleted;
            });
            
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Repository delete error', [
                'id' => $id,
                'exception' => $e->getMessage()
            ]);
            throw new DatabaseException('Error deleting record', 0, $e);
        }
    }

    /**
     * Generate cache key for operations
     */
    protected function getCacheKey(string $operation, ...$params): string
    {
        return sprintf(
            '%s:%s:%s',
            $this->cachePrefix,
            $operation,
            implode(':', $params)
        );
    }

    /**
     * Clear cache for model
     */
    protected function clearModelCache(int $id = null): void
    {
        $keys = ['list'];
        if ($id) {
            $keys[] = $this->getCacheKey('find', $id);
        }
        
        foreach ($keys as $key) {
            Cache::forget($key);
        }
    }
}
