<?php

namespace App\Core\Repositories;

use App\Core\Repositories\Contracts\CacheRepositoryInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Collection;

class CacheRepository implements CacheRepositoryInterface
{
    public function getStats(): array
    {
        return [
            'size' => $this->getCacheSize(),
            'keys_count' => $this->getKeysCount(),
            'hits' => $this->getHitCount(),
            'misses' => $this->getMissCount(),
            'uptime' => $this->getUptime(),
            'memory_usage' => $this->getMemoryUsage()
        ];
    }

    public function clearByTags(array $tags): bool
    {
        return Cache::tags($tags)->flush();
    }

    public function clearByPattern(string $pattern): int
    {
        $count = 0;
        $keys = $this->getKeysByPattern($pattern);

        foreach ($keys as $key) {
            if (Cache::forget($key)) {
                $count++;
            }
        }

        return $count;
    }

    public function getKeysByPattern(string $pattern): Collection
    {
        // Implementation depends on cache driver
        // Example for Redis:
        if (config('cache.default') === 'redis') {
            $redis = Cache::getRedis();
            return collect($redis->keys($pattern));
        }

        return collect();
    }

    public function warmUp(array $keys): array
    {
        $results = [];

        foreach ($keys as $key => $callback) {
            try {
                Cache::remember($key, now()->addDay(), $callback);
                $results[$key] = true;
            } catch (\Exception $e) {
                $results[$key] = false;
            }
        }

        return $results;
    }

    public function getTaggedKeys(array $tags): Collection
    {
        return $this->getKeysByPattern('tag:' . implode('|', $tags) . '*');
    }

    protected function getCacheSize(): int
    {
        if (config('cache.default') === 'redis') {
            $redis = Cache::getRedis();
            return $redis->dbSize();
        }

        return 0;
    }

    protected function getKeysCount(): int
    {
        if (config('cache.default') === 'redis') {
            return count($this->getKeysByPattern('*'));
        }

        return 0;
    }

    protected function getHitCount(): int
    {
        if (config('cache.default') === 'redis') {
            $redis = Cache::getRedis();
            $info = $redis->info();
            return $info['keyspace_hits'] ?? 0;
        }

        return 0;
    }

    protected function getMissCount(): int
    {
        if (config('cache.default') === 'redis') {
            $redis = Cache::getRedis();
            $info = $redis->info();
            return $info['keyspace_misses'] ?? 0;
        }

        return 0;
    }

    protected function getUptime(): int
    {
        if (config('cache.default') === 'redis') {
            $redis = Cache::getRedis();
            $info = $redis->info();
            return $info['uptime_in_seconds'] ?? 0;
        }

        return 0;
    }

    protected function getMemoryUsage(): int
    {
        if (config('cache.default') === 'redis') {
            $redis = Cache::getRedis();
            $info = $redis->info();
            return $info['used_memory'] ?? 0;
        }

        return 0;
    }
}
