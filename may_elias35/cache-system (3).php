// File: app/Core/Cache/Manager/CacheManager.php
<?php

namespace App\Core\Cache\Manager;

class CacheManager
{
    protected CacheStore $store;
    protected CacheStrategy $strategy;
    protected TagManager $tagManager;
    protected MetricsCollector $metrics;

    public function remember(string $key, \Closure $callback, ?int $ttl = null): mixed
    {
        if ($value = $this->get($key)) {
            $this->metrics->recordHit($key);
            return $value;
        }

        $value = $callback();
        $this->put($key, $value, $ttl);
        $this->metrics->recordMiss($key);

        return $value;
    }

    public function tags(array $tags): self
    {
        $this->tagManager->setTags($tags);
        return $this;
    }

    public function invalidate(array $tags = []): void
    {
        $this->tagManager->invalidate($tags);
    }

    protected function getStrategy(string $key, $value): CacheStrategy
    {
        return $this->strategy->determine($key, $value);
    }
}

// File: app/Core/Cache/Store/DistributedCache.php
<?php

namespace App\Core\Cache\Store;

class DistributedCache implements CacheStore
{
    protected ConnectionPool $pool;
    protected Serializer $serializer;
    protected LockManager $lockManager;

    public function get(string $key): mixed
    {
        $connection = $this->pool->getConnection();
        $value = $connection->get($key);

        if ($value === null) {
            return null;
        }

        return $this->serializer->unserialize($value);
    }

    public function put(string $key, mixed $value, ?int $ttl = null): void
    {
        $connection = $this->pool->getConnection();
        $serialized = $this->serializer->serialize($value);

        $connection->set($key, $serialized, $ttl);
    }

    public function delete(string $key): void
    {
        $connection = $this->pool->getConnection();
        $connection->delete($key);
    }

    public function clear(): void
    {
        $connection = $this->pool->getConnection();
        $connection->flush();
    }
}

// File: app/Core/Cache/Tag/TagManager.php
<?php

namespace App\Core\Cache\Tag;

class TagManager
{
    protected TagStore $store;
    protected TagGenerator $generator;
    protected array $currentTags = [];

    public function setTags(array $tags): void
    {
        $this->currentTags = $this->generator->normalize($tags);
    }

    public function getNamespace(): string
    {
        return $this->generator->generateNamespace($this->currentTags);
    }

    public function invalidate(array $tags): void
    {
        $namespace = $this->generator->generateNamespace($tags);
        $this->store->increment($namespace);
    }

    public function getTagVersions(array $tags): array
    {
        return array_map(function($tag) {
            return $this->store->get($tag, 1);
        }, $tags);
    }
}

// File: app/Core/Cache/Strategy/CacheStrategy.php
<?php

namespace App\Core\Cache\Strategy;

class CacheStrategy
{
    protected array $strategies;
    protected StrategyConfig $config;
    protected MetricsCollector $metrics;

    public function determine(string $key, $value): string
    {
        foreach ($this->strategies as $strategy) {
            if ($strategy->matches($key, $value)) {
                $this->metrics->recordStrategy($strategy->getName());
                return $strategy->getName();
            }
        }

        return $this->config->getDefaultStrategy();
    }

    public function addStrategy(Strategy $strategy): void
    {
        $this->strategies[] = $strategy;
    }
}
