<?php

namespace App\Core\Data;

use Illuminate\Support\Facades\{DB, Cache, Log};
use App\Core\Security\SecurityManager;
use App\Core\Contracts\{DataServiceInterface, CacheServiceInterface};
use App\Core\Exceptions\{DataException, CacheException};

class DataService implements DataServiceInterface
{
    private SecurityManager $security;
    private CacheService $cache;
    private QueryBuilder $queryBuilder;
    private MetricsCollector $metrics;

    public function __construct(
        SecurityManager $security,
        CacheService $cache,
        QueryBuilder $queryBuilder,
        MetricsCollector $metrics
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->queryBuilder = $queryBuilder;
        $this->metrics = $metrics;
    }

    public function executeQuery(string $model, array $criteria, array $relations = []): Collection
    {
        $startTime = microtime(true);
        $cacheKey = $this->generateCacheKey($model, $criteria, $relations);

        try {
            return $this->cache->remember($cacheKey, fn() => 
                $this->queryBuilder
                    ->for($model)
                    ->withCriteria($criteria)
                    ->withRelations($relations)
                    ->get()
            );
        } finally {
            $this->metrics->recordQueryTime(
                $model,
                microtime(true) - $startTime
            );
        }
    }

    public function executeWrite(string $model, array $data): Model
    {
        DB::beginTransaction();
        
        try {
            $result = $this->queryBuilder
                ->for($model)
                ->create($data);

            $this->cache->invalidateModel($model);
            
            DB::commit();
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw new DataException("Write operation failed: {$e->getMessage()}");
        }
    }

    public function executeUpdate(string $model, $id, array $data): Model
    {
        DB::beginTransaction();
        
        try {
            $result = $this->queryBuilder
                ->for($model)
                ->find($id)
                ->update($data);

            $this->cache->invalidateModel($model, $id);
            
            DB::commit();
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw new DataException("Update operation failed: {$e->getMessage()}");
        }
    }

    private function generateCacheKey(string $model, array $criteria, array $relations): string
    {
        return md5(serialize([
            'model' => $model,
            'criteria' => $criteria,
            'relations' => $relations
        ]));
    }
}

class CacheService implements CacheServiceInterface
{
    private const DEFAULT_TTL = 3600;
    private const PROTECTION_THRESHOLD = 1000;
    
    private MetricsCollector $metrics;

    public function __construct(MetricsCollector $metrics)
    {
        $this->metrics = $metrics;
    }

    public function remember(string $key, callable $callback, ?int $ttl = null): mixed
    {
        $startTime = microtime(true);
        
        try {
            if ($value = Cache::get($key)) {
                $this->metrics->recordCacheHit($key);
                return $value;
            }

            $value = $callback();
            
            if ($this->shouldCache($value)) {
                Cache::put($key, $value, $ttl ?? self::DEFAULT_TTL);
            }

            $this->metrics->recordCacheMiss($key);
            return $value;
            
        } finally {
            $this->metrics->recordCacheOperationTime(
                $key,
                microtime(true) - $startTime
            );
        }
    }

    public function invalidateModel(string $model, ?int $id = null): void
    {
        $pattern = $id 
            ? "model:{$model}:{$id}:*"
            : "model:{$model}:*";

        foreach (Cache::get($pattern) as $key) {
            Cache::forget($key);
        }
    }

    private function shouldCache($value): bool
    {
        if (is_array($value)) {
            return count($value) < self::PROTECTION_THRESHOLD;
        }
        if ($value instanceof Collection) {
            return $value->count() < self::PROTECTION_THRESHOLD;
        }
        return true;
    }
}

class QueryBuilder
{
    private $query;
    private string $model;

    public function for(string $model): self
    {
        $this->model = $model;
        $this->query = $model::query();
        return $this;
    }

    public function withCriteria(array $criteria): self
    {
        foreach ($criteria as $field => $value) {
            if (is_array($value)) {
                $this->query->whereIn($field, $value);
            } else {
                $this->query->where($field, $value);
            }
        }
        return $this;
    }

    public function withRelations(array $relations): self
    {
        $this->query->with($relations);
        return $this;
    }

    public function get(): Collection
    {
        return $this->query->get();
    }

    public function find($id): self
    {
        $this->query->findOrFail($id);
        return $this;
    }

    public function create(array $data): Model
    {
        return $this->query->create($data);
    }

    public function update(array $data): Model
    {
        return tap($this->query, function($query) use ($data) {
            $query->update($data);
        })->first();
    }
}

class MetricsCollector
{
    public function recordQueryTime(string $model, float $time): void
    {
        Log::channel('metrics')->info('query_time', [
            'model' => $model,
            'time' => $time
        ]);
    }

    public function recordCacheHit(string $key): void
    {
        Log::channel('metrics')->info('cache_hit', [
            'key' => $key
        ]);
    }

    public function recordCacheMiss(string $key): void
    {
        Log::channel('metrics')->info('cache_miss', [
            'key' => $key
        ]);
    }

    public function recordCacheOperationTime(string $key, float $time): void
    {
        Log::channel('metrics')->info('cache_operation', [
            'key' => $key,
            'time' => $time
        ]);
    }
}
