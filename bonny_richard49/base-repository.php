<?php

namespace App\Core\Repository;

use Illuminate\Database\Eloquent\Model;
use App\Core\Services\{
    CacheManager,
    ValidationService
};
use App\Core\Interfaces\RepositoryInterface;

abstract class BaseRepository implements RepositoryInterface
{
    protected Model $model;
    protected CacheManager $cache;
    protected ValidationService $validator;

    public function __construct(
        Model $model,
        CacheManager $cache,
        ValidationService $validator
    ) {
        $this->model = $model;
        $this->cache = $cache;
        $this->validator = $validator;
    }

    /**
     * Find a record with caching and validation
     */
    public function find(int $id): ?Model 
    {
        return $this->cache->remember(
            $this->getCacheKey('find', $id),
            function() use ($id) {
                $result = $this->model->find($id);
                
                if ($result && !$this->validateData($result)) {
                    throw new DataIntegrityException('Data validation failed');
                }
                
                return $result;
            }
        );
    }

    /**
     * Store a record with validation
     */
    public function store(array $data): Model
    {
        // Validate input data
        $validatedData = $this->validateData(
            $data,
            $this->getValidationRules()
        );

        DB::beginTransaction();
        
        try {
            $model = $this->model->create($validatedData);
            
            // Clear relevant cache
            $this->cache->tags($this->getCacheTags())
                ->flush();
            
            DB::commit();
            return $model;
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Update a record with validation
     */
    public function update(int $id, array $data): Model
    {
        $validatedData = $this->validateData(
            $data,
            $this->getValidationRules()
        );

        DB::beginTransaction();
        
        try {
            $model = $this->model->findOrFail($id);
            $model->update($validatedData);
            
            // Clear cache
            $this->cache->tags($this->getCacheTags())
                ->flush();
            
            DB::commit();
            return $model;
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Delete a record with cache clear
     */
    public function delete(int $id): bool
    {
        DB::beginTransaction();
        
        try {
            $result = $this->model->findOrFail($id)->delete();
            
            // Clear cache
            $this->cache->tags($this->getCacheTags())
                ->flush();
            
            DB::commit();
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Get cache key for operation
     */
    protected function getCacheKey(string $operation, ...$params): string
    {
        return sprintf(
            '%s:%s:%s',
            $this->model->getTable(),
            $operation,
            implode(':', $params)
        );
    }

    /**
     * Get cache tags for model
     */
    protected function getCacheTags(): array
    {
        return [
            $this->model->getTable()
        ];
    }

    /**
     * Validate data against rules
     */
    protected function validateData(array $data, array $rules = []): array
    {
        return $this->validator->validate($data, $rules);
    }

    /**
     * Get validation rules for model
     */
    abstract protected function getValidationRules(): array;
}
