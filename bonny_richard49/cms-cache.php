<?php

namespace App\Core\CMS\Cache;

class ContentCacheManager implements CacheManagerInterface
{
    private Cache $cache;
    private SecurityManager $security;
    private CacheConfig $config;

    public function __construct(
        Cache $cache, 
        SecurityManager $security,
        CacheConfig $config
    ) {
        $this->cache = $cache;
        $this->security = $security;
        $this->config = $config;
    }

    public function get(string $key, SecurityContext $context): mixed
    {
        $operation = new CacheReadOperation($key);
        return $this->security->executeCriticalOperation($operation, $context);
    }

    public function put(string $key, mixed $value, SecurityContext $context): void
    {
        $operation = new CacheWriteOperation($key, $value);
        $this->security->executeCriticalOperation($operation, $context);
    }

    public function invalidate(string $key, SecurityContext $context): void
    {
        $operation = new CacheInvalidationOperation($key);
        $this->security->executeCriticalOperation($operation, $context);
    }
}

class CacheReadOperation implements CriticalOperation
{
    private string $key;
    private Cache $cache;

    public function __construct(string $key, Cache $cache)
    {
        $this->key = $key;
        $this->cache = $cache;
    }

    public function execute(): OperationResult
    {
        $value = $this->cache->get($this->key);
        return new OperationResult($value);
    }

    public function getRequiredPermissions(): array
    {
        return ['cache.read'];
    }
}

class CacheWriteOperation implements CriticalOperation
{
    private string $key;
    private mixed $value;
    private Cache $cache;
    private CacheConfig $config;

    public function __construct(string $key, mixed $value, Cache $cache, CacheConfig $config)
    {
        $this->key = $key;
        $this->value = $value;
        $this->cache = $cache;
        $this->config = $config;
    }

    public function execute(): OperationResult
    {
        $ttl = $this->config->getCacheTTL();
        $this->cache->put($this->key, $this->value, $ttl);
        return new OperationResult(true);
    }

    public function getRequiredPermissions(): array
    {
        return ['cache.write'];
    }
}