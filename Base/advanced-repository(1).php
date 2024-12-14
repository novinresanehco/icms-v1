<?php

namespace App\Core\Repositories;

use Illuminate\Support\Facades\DB;
use App\Core\Cache\CacheManager;

abstract class AdvancedRepository extends AbstractRepository 
{
    protected $cacheManager;
    protected $cacheTtl = 3600;
    protected $useCache = true;

    public function __construct(CacheManager $cacheManager)
    {
        $this->cacheManager = $cacheManager;
    }

    protected function cacheKey(string $method, ...$args): string 
    {
        return sprintf(
            '%s:%s:%s',
            $this->model->getTable(),
            $method,
            md5(serialize($args))
        );
    }

    protected function executeWithCache(string $method, callable $callback, ...$args)
    {
        if (!$this->useCache) {
            return $callback();
        }

        $key = $this->cacheKey($method, ...$args);
        
        return $this->cacheManager->remember($key, $this->cacheTtl, $callback);
    }

    protected function executeTransaction(callable $callback)
    {
        try {
            DB::beginTransaction();
            $result = $callback();
            DB::commit();
            return $result;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function invalidateCache(string $method, ...$args): void
    {
        $key = $this->cacheKey($method, ...$args);
        $this->cacheManager->forget($key);
    }
}
