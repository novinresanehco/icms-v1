<?php

namespace App\Core\Cache;

class CacheManager implements CacheManagerInterface
{
    private CacheStore $store;
    private SecurityManager $security;

    public function remember(array $key, callable $callback, ?int $ttl = null): mixed
    {
        $cacheKey = $this->buildKey($key);
        
        if ($cached = $this->get($cacheKey)) {
            return $cached;
        }

        $value = $callback();
        $this->set($cacheKey, $value, $ttl);
        return $value;
    }

    public function get(string $key): mixed
    {
        $value = $this->store->get($key);
        return $value ? $this->security->decrypt($value) : null;
    }

    public function set(string $key, mixed $value, ?int $ttl = null): void
    {
        $encrypted = $this->security->encrypt(serialize($value));
        $this->store->put($key, $encrypted, $ttl ?? config('cache.ttl'));
    }

    public function invalidate(array|string $keys): void
    {
        $keys = (array)$keys;
        foreach ($keys as $key) {
            $this->store->forget($this->buildKey($key));
        }
    }

    private function buildKey(array|string $key): string
    {
        $key = is_array($key) ? implode(':', $key) : $key;
        return hash('sha256', $key);
    }
}

class QueryCache implements QueryCacheInterface
{
    private CacheManager $cache;
    
    public function getQueryResult(string $sql, array $bindings): mixed
    {
        return $this->cache->remember(
            ['query', md5($sql . serialize($bindings))],
            fn() => DB::select($sql, $bindings),
            config('cache.query_ttl')
        );
    }

    public function invalidateQuery(string $table): void
    {
        $pattern = "query:*";
        if (method_exists($this->cache, 'deletePattern')) {
            $this->cache->deletePattern($pattern);
        }
    }
}

class ViewCache implements ViewCacheInterface
{
    private CacheManager $cache;
    private SecurityManager $security;

    public function rememberView(string $view, array $data, array $mergeData = []): string
    {
        return $this->cache->remember(
            ['view', $this->getViewKey($view, $data, $mergeData)],
            fn() => view($view, $data, $mergeData)->render(),
            config('cache.view_ttl')
        );
    }

    private function getViewKey(string $view, array $data, array $mergeData): string
    {
        $content = $view . serialize($data) . serialize($mergeData);
        return $this->security->generateChecksum($content);
    }
}

class ResourceCache implements ResourceCacheInterface
{
    private CacheManager $cache;

    public function rememberResource(string $type, int $id, callable $callback): mixed
    {
        return $this->cache->remember(
            ['resource', $type, $id],
            $callback,
            config('cache.resource_ttl')
        );
    }

    public function invalidateResource(string $type, int $id): void
    {
        $this->cache->invalidate(['resource', $type, $id]);
    }
}
