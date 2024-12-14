<?php

namespace App\Core\Repository;

use App\Core\Security\SecurityManager;
use App\Core\Validation\ValidationService;
use App\Core\Cache\CacheManager;
use Illuminate\Database\Eloquent\{Model, Builder};
use Illuminate\Support\Facades\DB;

abstract class CriticalRepository
{
    protected Model $model;
    protected SecurityManager $security;
    protected ValidationService $validator;
    protected CacheManager $cache;
    protected string $context;

    public function __construct(
        Model $model,
        SecurityManager $security,
        ValidationService $validator,
        CacheManager $cache
    ) {
        $this->model = $model;
        $this->security = $security;
        $this->validator = $validator;
        $this->cache = $cache;
        $this->context = static::class;
    }

    /**
     * Execute critical operation with protection
     */
    protected function executeCritical(string $operation, callable $action, array $context = []): mixed
    {
        $this->validator->validateOperation($operation, $context);
        $this->security->validateAccess($operation, $context);
        
        DB::beginTransaction();
        
        try {
            $result = $action();
            
            $this->validator->validateResult($result);
            
            DB::commit();
            
            $this->cache->invalidateContext($this->context);
            
            return $result;
            
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $this->handleRepositoryError($e, $operation, $context);
        }
    }

    /**
     * Find by ID with security
     */
    public function find(int $id, array $with = []): ?Model
    {
        return $this->executeCritical('find', function() use ($id, $with) {
            return $this->cache->remember(
                $this->getCacheKey('find', $id),
                fn() => $this->model->with($with)->find($id)
            );
        }, ['id' => $id]);
    }

    /**
     * Create with validation
     */
    public function create(array $data): Model
    {
        return $this->executeCritical('create', function() use ($data) {
            $validated = $this->validator->validate($data);
            
            return $this->model->create($validated);
        }, ['data' => $data]);
    }

    /**
     * Update with validation
     */
    public function update(Model $model, array $data): Model
    {
        return $this->executeCritical('update', function() use ($model, $data) {
            $validated = $this->validator->validate($data);
            
            $model->update($validated);
            
            return $model->fresh();
        }, ['id' => $model->id, 'data' => $data]);
    }

    /**
     * Delete with security
     */
    public function delete(Model $model): bool
    {
        return $this->executeCritical('delete', function() use ($model) {
            return $model->delete();
        }, ['id' => $model->id]);
    }

    /**
     * Find with locking
     */
    protected function findWithLock(int $id): ?Model
    {
        return $this->model->lockForUpdate()->find($id);
    }

    /**
     * Handle repository errors
     */
    protected function handleRepositoryError(\Throwable $e, string $operation, array $context): \Throwable
    {
        $error = new RepositoryException(
            "Repository operation failed: {$operation}",
            previous: $e
        );

        $this->security->logError($error, [
            'repository' => static::class,
            'operation' => $operation,
            'context' => $context
        ]);

        return $error;
    }

    /**
     * Generate cache key
     */
    protected function getCacheKey(string $operation, ...$params): string
    {
        return sprintf(
            '%s:%s:%s',
            $this->context,
            $operation,
            md5(serialize($params))
        );
    }

    /**
     * Get query builder
     */
    protected function getQueryBuilder(): Builder
    {
        return $this->model->newQuery();
    }

    /**
     * Validate query parameters
     */
    protected function validateQueryParams(array $params): array
    {
        return $this->validator->validateQueryParams($params);
    }
}

class ContentRepository extends CriticalRepository
{
    /**
     * Find published content
     */
    public function findPublished(array $criteria = []): array
    {
        return $this->executeCritical('findPublished', function() use ($criteria) {
            $validated = $this->validateQueryParams($criteria);
            
            return $this->cache->remember(
                $this->getCacheKey('published', $validated),
                fn() => $this->getPublishedQuery($validated)->get()
            );
        }, ['criteria' => $criteria]);
    }

    /**
     * Find with version history
     */
    public function findWithHistory(int $id): ?Model
    {
        return $this->executeCritical('findWithHistory', function() use ($id) {
            return $this->cache->remember(
                $this->getCacheKey('history', $id),
                fn() => $this->model->with('versions')->find($id)
            );
        }, ['id' => $id]);
    }

    /**
     * Get published content query
     */
    private function getPublishedQuery(array $criteria): Builder
    {
        return $this->getQueryBuilder()
            ->where('status', 'published')
            ->when(
                isset($criteria['category']),
                fn($q) => $q->where('category_id', $criteria['category'])
            )
            ->when(
                isset($criteria['tag']),
                fn($q) => $q->whereHas('tags', fn($q) => $q->where('name', $criteria['tag']))
            )
            ->latest();
    }
}

class MediaRepository extends CriticalRepository
{
    /**
     * Find by hash
     */
    public function findByHash(string $hash): ?Model
    {
        return $this->executeCritical('findByHash', function() use ($hash) {
            return $this->cache->remember(
                $this->getCacheKey('hash', $hash),
                fn() => $this->model->where('hash', $hash)->first()
            );
        }, ['hash' => $hash]);
    }

    /**
     * Find unused media
     */
    public function findUnused(int $days = 30): array
    {
        return $this->executeCritical('findUnused', function() use ($days) {
            return $this->model
                ->whereDoesntHave('content')
                ->where('created_at', '<', now()->subDays($days))
                ->get();
        }, ['days' => $days]);
    }
}
