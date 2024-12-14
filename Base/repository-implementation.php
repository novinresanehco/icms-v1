<?php

namespace App\Core\Repositories;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use App\Core\Cache\CacheManager;
use App\Core\Events\EventDispatcher;

abstract class AbstractRepository
{
    protected Model $model;
    protected CacheManager $cache;
    protected EventDispatcher $events;
    protected array $with = [];
    protected array $searchable = [];
    protected int $cacheTtl = 3600;
    protected bool $useCache = true;

    public function __construct(Model $model, CacheManager $cache, EventDispatcher $events)
    {
        $this->model = $model;
        $this->cache = $cache;
        $this->events = $events;
    }

    protected function createQueryBuilder(): QueryBuilder
    {
        return $this->model->newQuery()->with($this->with);
    }

    protected function executeQuery(callable $callback)
    {
        if (!$this->useCache) {
            return $callback();
        }

        $key = $this->generateCacheKey(debug_backtrace()[1]['function'], func_get_args());
        return $this->cache->remember($key, $this->cacheTtl, $callback);
    }

    protected function invalidateCache(): void
    {
        $this->cache->tags($this->getCacheTags())->flush();
        $this->events->dispatch('repository.cache.invalidated', $this->model);
    }

    protected function getCacheTags(): array
    {
        return [
            $this->model->getTable(),
            'repository',
            static::class
        ];
    }

    protected function generateCacheKey(string $method, array $args = []): string
    {
        $key = sprintf(
            '%s:%s:%s',
            $this->model->getTable(),
            $method,
            md5(serialize($args))
        );
        return strtolower($key);
    }

    public function beginTransaction(): void
    {
        $this->model->getConnection()->beginTransaction();
    }

    public function commit(): void
    {
        $this->model->getConnection()->commit();
        $this->invalidateCache();
    }

    public function rollback(): void
    {
        $this->model->getConnection()->rollBack();
    }
}

class VersionedRepository extends AbstractRepository
{
    public function createVersion(int $id, array $data): Model
    {
        $this->beginTransaction();
        
        try {
            $current = $this->findOrFail($id);
            $version = $current->versions()->create([
                'data' => $data,
                'created_by' => auth()->id(),
                'version' => $current->versions()->max('version') + 1
            ]);
            
            $this->commit();
            return $version;
            
        } catch (\Exception $e) {
            $this->rollback();
            throw $e;
        }
    }

    public function getVersions(int $id): Collection
    {
        return $this->executeQuery(function() use ($id) {
            return $this->model->findOrFail($id)
                ->versions()
                ->with('creator')
                ->orderByDesc('version')
                ->get();
        });
    }

    public function revertToVersion(int $id, int $versionId): Model
    {
        $this->beginTransaction();
        
        try {
            $version = $this->model->findOrFail($id)
                ->versions()
                ->findOrFail($versionId);
                
            $model = $this->update($id, $version->data);
            
            $this->commit();
            return $model;
            
        } catch (\Exception $e) {
            $this->rollback();
            throw $e;
        }
    }
}

class SearchableRepository extends AbstractRepository
{
    public function search(string $query, array $options = []): Collection
    {
        return $this->executeQuery(function() use ($query, $options) {
            $builder = $this->createQueryBuilder();
            
            foreach ($this->searchable as $field) {
                $builder->orWhere($field, 'LIKE', "%{$query}%");
            }
            
            if (!empty($options['filters'])) {
                $this->applyFilters($builder, $options['filters']);
            }
            
            if (!empty($options['sort'])) {
                $this->applySort($builder, $options['sort']);
            }
            
            return $builder->get();
        });
    }

    protected function applyFilters($builder, array $filters): void
    {
        foreach ($filters as $field => $value) {
            if (is_array($value)) {
                $builder->whereIn($field, $value);
            } else {
                $builder->where($field, $value);
            }
        }
    }

    protected function applySort($builder, array $sort): void
    {
        foreach ($sort as $field => $direction) {
            $builder->orderBy($field, $direction);
        }
    }
}
