<?php

namespace App\Core\Audit\Caching;

class CacheManager
{
    private CacheInterface $cache;
    private CacheKeyGenerator $keyGenerator;
    private array $config;
    private LoggerInterface $logger;

    public function __construct(
        CacheInterface $cache,
        CacheKeyGenerator $keyGenerator,
        array $config,
        LoggerInterface $logger
    ) {
        $this->cache = $cache;
        $this->keyGenerator = $keyGenerator;
        $this->config = $config;
        $this->logger = $logger;
    }

    public function remember(string $key, callable $callback, ?int $ttl = null): mixed
    {
        $cacheKey = $this->keyGenerator->generate($key);
        
        if ($cached = $this->cache->get($cacheKey)) {
            $this->logger->debug('Cache hit', ['key' => $key]);
            return $cached;
        }

        $value = $callback();
        
        $this->cache->set(
            $cacheKey, 
            $value, 
            $ttl ?? $this->config['default_ttl'] ?? 3600
        );

        $this->logger->debug('Cache miss', ['key' => $key]);
        
        return $value;
    }

    public function tags(array $tags): self
    {
        return new self(
            $this->cache->tags($tags),
            $this->keyGenerator,
            $this->config,
            $this->logger
        );
    }

    public function flush(array $tags = []): void
    {
        if (empty($tags)) {
            $this->cache->flush();
        } else {
            $this->cache->tags($tags)->flush();
        }
    }
}

class CacheKeyGenerator
{
    private string $prefix;
    private array $config;

    public function __construct(string $prefix = '', array $config = [])
    {
        $this->prefix = $prefix;
        $this->config = $config;
    }

    public function generate(string $key, array $params = []): string
    {
        $parts = [
            $this->prefix,
            $key,
            $this->generateParamsHash($params)
        ];

        return implode(':', array_filter($parts));
    }

    private function generateParamsHash(array $params): string
    {
        if (empty($params)) {
            return '';
        }

        ksort($params);
        return hash('sha256', serialize($params));
    }
}

class DistributedCache
{
    private array $nodes;
    private ConsistentHasher $hasher;
    private LoggerInterface $logger;

    public function __construct(array $nodes, ConsistentHasher $hasher, LoggerInterface $logger)
    {
        $this->nodes = $nodes;
        $this->hasher = $hasher;
        $this->logger = $logger;
    }

    public function get(string $key)
    {
        $node = $this->getNode($key);
        
        try {
            return $node->get($key);
        } catch (\Exception $e) {
            $this->logger->error('Cache read failed', [
                'node' => $node->getId(),
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    public function set(string $key, $value, int $ttl = null): void
    {
        $node = $this->getNode($key);
        
        try {
            $node->set($key, $value, $ttl);
        } catch (\Exception $e) {
            $this->logger->error('Cache write failed', [
                'node' => $node->getId(),
                'key' => $key,
                'error' => $e->getMessage()
            ]);
        }
    }

    public function delete(string $key): void
    {
        $node = $this->getNode($key);
        
        try {
            $node->delete($key);
        } catch (\Exception $e) {
            $this->logger->error('Cache delete failed', [
                'node' => $node->getId(),
                'key' => $key,
                'error' => $e->getMessage()
            ]);
        }
    }

    private function getNode(string $key): CacheNode
    {
        $nodeId = $this->hasher->getNode($key);
        return $this->nodes[$nodeId];
    }
}

class TaggedCache
{
    private CacheInterface $cache;
    private TagSetGenerator $tagGenerator;

    public function __construct(CacheInterface $cache, TagSetGenerator $tagGenerator)
    {
        $this->cache = $cache;
        $this->tagGenerator = $tagGenerator;
    }

    public function get(string $key)
    {
        $tags = $this->tagGenerator->getTagsForKey($key);
        if (!$this->areTagsValid($tags)) {
            return null;
        }
        
        return $this->cache->get($key);
    }

    public function set(string $key, $value, array $tags = [], ?int $ttl = null): void
    {
        $this->cache->set($key, $value, $ttl);
        $this->tagGenerator->setTagsForKey($key, $tags);
    }

    public function flush(array $tags): void
    {
        $this->tagGenerator->invalidateTags($tags);
    }

    private function areTagsValid(array $tags): bool
    {
        foreach ($tags as $tag) {
            if (!$this->tagGenerator->isTagValid($tag)) {
                return false;
            }
        }
        return true;
    }
}

class CacheWarmer
{
    private CacheInterface $cache;
    private array $warmers;
    private LoggerInterface $logger;

    public function __construct(
        CacheInterface $cache,
        array $warmers,
        LoggerInterface $logger
    ) {
        $this->cache = $cache;
        $this->warmers = $warmers;
        $this->logger = $logger;
    }

    public function warm(): void
    {
        foreach ($this->warmers as $warmer) {
            try {
                $items = $warmer->getItems();
                foreach ($items as $key => $value) {
                    $this->cache->set($key, $value, $warmer->getTtl());
                }
                
                $this->logger->info('Cache warmed', [
                    'warmer' => get_class($warmer),
                    'items' => count($items)
                ]);
            } catch (\Exception $e) {
                $this->logger->error('Cache warming failed', [
                    'warmer' => get_class($warmer),
                    'error' => $e->getMessage()
                ]);
            }
        }
    }
}
