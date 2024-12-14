<?php

namespace App\Core\Repository;

use Illuminate\Database\Eloquent\Model;
use App\Core\Security\SecurityManagerInterface;
use App\Core\Cache\CacheManagerInterface;
use App\Core\Validation\ValidationServiceInterface;
use App\Core\Monitoring\MonitoringServiceInterface;

abstract class BaseRepository
{
    protected Model $model;
    protected SecurityManagerInterface $security;
    protected CacheManagerInterface $cache;
    protected ValidationServiceInterface $validator;
    protected MonitoringServiceInterface $monitor;

    public function __construct(
        Model $model,
        SecurityManagerInterface $security,
        CacheManagerInterface $cache,
        ValidationServiceInterface $validator,
        MonitoringServiceInterface $monitor
    ) {
        $this->model = $model;
        $this->security = $security;
        $this->cache = $cache;
        $this->validator = $validator;
        $this->monitor = $monitor;
    }

    /**
     * Critical operation execution with complete protection
     */
    protected function executeCriticalOperation(string $operation, callable $callback, array $context = []): mixed
    {
        // Start operation monitoring
        $operationId = $this->monitor->startOperation($operation);

        try {
            // Execute with security controls
            return $this->security->executeCriticalOperation(function() use ($callback) {
                return $callback();
            }, array_merge($context, ['operation_id' => $operationId]));

        } finally {
            $this->monitor->stopOperation($operationId);
        }
    }

    /**
     * Secure find operation with caching
     */
    public function find(int $id): ?Model
    {
        return $this->executeCriticalOperation('find', function() use ($id) {
            return $this->cache->remember(
                $this->getCacheKey('find', $id),
                fn() => $this->model->find($id)
            );
        }, ['id' => $id]);
    }

    /**
     * Secure create operation with validation
     */
    public function create(array $data): Model
    {
        return $this->executeCriticalOperation('create', function() use ($data) {
            $validated = $this->validator->validate($data, $this->getValidationRules());
            
            $model = $this->model->create($validated);
            
            $this->cache->invalidatePattern($this->getCachePattern());
            
            return $model;
        }, ['data' => $data]);
    }

    /**
     * Secure update operation with validation
     */
    public function update(int $id, array $data): Model
    {
        return $this->executeCriticalOperation('update', function() use ($id, $data) {
            $model = $this->model->findOrFail($id);
            
            $validated = $this->validator->validate($data, $this->getValidationRules());
            
            $model->update($validated);
            
            $this->cache->invalidatePattern($this->getCachePattern());
            
            return $model;
        }, ['id' => $id, 'data' => $data]);
    }

    /**
     * Secure delete operation
     */
    public function delete(int $id): bool
    {
        return $this->executeCriticalOperation('delete', function() use ($id) {
            $model = $this->model->findOrFail($id);
            
            $deleted = $model->delete();
            
            $this->cache->invalidatePattern($this->getCachePattern());
            
            return $deleted;
        }, ['id' => $id]);
    }

    abstract protected function getValidationRules(): array;
    abstract protected function getCacheKey(string $operation, ...$params): string;
    abstract protected function getCachePattern(): string;
}
