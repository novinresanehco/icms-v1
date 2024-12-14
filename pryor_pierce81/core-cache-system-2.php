<?php

namespace App\Core\Cache;

use App\Core\Security\SecurityManager;
use App\Core\Exceptions\{CacheException, SecurityException};
use Illuminate\Support\Facades\Cache;
use Psr\SimpleCache\CacheInterface;

class CacheManager implements CacheInterface
{
    private SecurityManager $security;
    private MetricsCollector $metrics;
    private CacheStore $store;
    private array $config;

    public function __construct(
        SecurityManager $security,
        MetricsCollector $metrics,
        CacheStore $store,
        array $config
    ) {
        $this->security = $security;
        $this->metrics = $metrics;
        $this->store = $store;
        $this->config = $config;
    }

    public function remember(string $key, callable $callback, ?int $ttl = null): mixed
    {
        $startTime = microtime(true);

        try {
            if ($value = $this->get($key)) {
                $this->metrics->incrementHits($key);
                return $value;
            }

            $value = $callback();
            $this->set($key, $value, $ttl);
            $this->metrics->incrementMisses($key);

            return $value;

        } catch (\Exception $e) {
            $this->metrics->incrementErrors($key);
            throw new CacheException("Cache operation failed: {$e->getMessage()}", 0, $e);
        } finally {
            $this->metrics->recordOperationTime(
                $key,
                microtime(true) - $startTime
            );
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        try {
            $value = $this->store->get($this->normalizeKey($key));

            if ($value === null) {
                return $default;
            }

            if (!$this->validateValue($value)) {
                $this->delete($key);
                return $default;
            }

            return $this->decodeValue($value);

        } catch (\Exception $e) {
            $this->handleError($e);
            return $default;
        }
    }

    public function set(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        try {
            $normalizedKey = $this->normalizeKey($key);
            $encodedValue = $this->encodeValue($value);
            $ttl = $this->normalizeTtl($ttl);

            return $this->store->set(
                $normalizedKey,
                $encodedValue,
                $ttl
            );

        } catch (\Exception $e) {
            $this->handleError($e);
            return false;
        }
    }

    public function delete(string $key): bool
    {
        try {
            return $this->store->delete($this->normalizeKey($key));
        } catch (\Exception $e) {
            $this->handleError($e);
            return false;
        }
    }

    public function clear(): bool
    {
        try {
            return $this->store->clear();
        } catch (\Exception $e) {
            $this->handleError($e);
            return false;
        }
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }
        return $result;
    }

    public function setMultiple(iterable $values, \DateInterval|int|null $ttl = null): bool
    {
        $success = true;
        foreach ($values as $key => $value) {
            if (!$this->set($key, $value, $ttl)) {
                $success = false;
            }
        }
        return $success;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        $success = true;
        foreach ($keys as $key) {
            if (!$this->delete($key)) {
                $success = false;
            }
        }
        return $success;
    }

    public function has(string $key): bool
    {
        try {
            return $this->store->has($this->normalizeKey($key));
        } catch (\Exception $e) {
            $this->handleError($e);
            return false;
        }
    }

    public function tags(array $tags): TaggedCache
    {
        return new TaggedCache($this, $tags);
    }

    private function validateValue($value): bool
    {
        if (!is_array($value) || !isset($value['hash'], $value['data'])) {
            return false;
        }

        return hash_equals(
            $value['hash'],
            $this->hashData($value['data'])
        );
    }

    private function encodeValue($value): array
    {
        $encoded = serialize($value);
        return [
            'data' => $encoded,
            'hash' => $this->hashData($encoded)
        ];
    }

    private function decodeValue(array $value): mixed
    {
        return unserialize($value['data']);
    }

    private function hashData(string $data): string
    {
        return hash_hmac('sha256', $data, $this->config['key']);
    }

    private function normalizeKey(string $key): string
    {
        return preg_replace('/[^A-Za-z0-9._-]/', '', $key);
    }

    private function normalizeTtl($ttl): ?int
    {
        if ($ttl === null) {
            return $this->config['default_ttl'];
        }

        if ($ttl instanceof \DateInterval) {
            return (new \DateTime())->add($ttl)->getTimestamp() - time();
        }

        return (int) $ttl;
    }

    private function handleError(\Exception $e): void
    {
        if ($e instanceof SecurityException) {
            throw $e;
        }

        // Log error but don't throw to maintain cache transparency
        $this->metrics->incrementErrors(get_class($e));
    }
}

class TaggedCache
{
    private CacheManager $cache;
    private array $tags;

    public function __construct(CacheManager $cache, array $tags)
    {
        $this->cache = $cache;
        $this->tags = $tags;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->cache->get($this->taggedKey($key), $default);
    }

    public function set(string $key, mixed $value, $ttl = null): bool
    {
        return $this->cache->set($this->taggedKey($key), $value, $ttl);
    }

    public function flush(): bool
    {
        $pattern = $this->getTagPattern();
        return $this->cache->deleteMultiple($this->cache->keys($pattern));
    }

    private function taggedKey(string $key): string
    {
        return implode(':', array_merge($this->tags, [$key]));
    }

    private function getTagPattern(): string
    {
        return implode(':', array_merge($this->tags, ['*']));
    }
}
