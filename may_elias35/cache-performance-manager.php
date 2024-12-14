<?php

namespace App\Core\Performance;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use App\Core\Security\SecurityManager;
use App\Core\Interfaces\CacheManagerInterface;

class CacheManager implements CacheManagerInterface
{
    private SecurityManager $security;
    private IntegrityVerifier $verifier;
    private MetricsCollector $metrics;
    private array $config;

    public function __construct(
        SecurityManager $security,
        IntegrityVerifier $verifier,
        MetricsCollector $metrics,
        array $config
    ) {
        $this->security = $security;
        $this->verifier = $verifier;
        $this->metrics = $metrics;
        $this->config = $config;
    }

    public function remember(string $key, int $ttl, callable $callback): mixed
    {
        $cacheKey = $this->generateKey($key);
        $startTime = microtime(true);

        try {
            if ($cached = $this->get($cacheKey)) {
                $this->metrics->recordHit($key, microtime(true) - $startTime);
                return $cached;
            }

            $value = $callback();
            $this->set($cacheKey, $value, $ttl);
            $this->metrics->recordMiss($key, microtime(true) - $startTime);

            return $value;

        } catch (\Exception $e) {
            $this->metrics->recordError($key, $e);
            throw $e;
        }
    }

    public function tags(array $tags): self
    {
        return new TaggedCache($this, $tags);
    }

    public function get(string $key): mixed
    {
        $value = Cache::get($key);
        
        if ($value && !$this->verifier->verify($key, $value)) {
            $this->invalidate($key);
            return null;
        }

        return $value;
    }

    public function set(string $key, mixed $value, int $ttl): bool
    {
        $signature = $this->verifier->sign($key, $value);
        
        return Cache::tags($this->getTags($key))->put(
            $key,
            [
                'data' => $value,
                'signature' => $signature,
                'timestamp' => time()
            ],
            $ttl
        );
    }

    public function invalidate(string $key): bool
    {
        return Cache::tags($this->getTags($key))->forget($key);
    }

    public function flush(array $tags = []): bool
    {
        if (empty($tags)) {
            return Cache::flush();
        }
        
        return Cache::tags($tags)->flush();
    }

    public function warmUp(array $keys): void
    {
        foreach ($keys as $key => $callback) {
            if (!$this->get($key)) {
                $this->remember($key, $this->config['ttl'], $callback);
            }
        }
    }

    private function generateKey(string $key): string
    {
        return hash('sha256', $key . $this->config['salt']);
    }

    private function getTags(string $key): array
    {
        return array_merge(
            $this->config['global_tags'],
            $this->parseKeyTags($key)
        );
    }

    private function parseKeyTags(string $key): array
    {
        $parts = explode(':', $key);
        return array_slice($parts, 0, -1);
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

    public function remember(string $key, int $ttl, callable $callback): mixed
    {
        return $this->cache->remember(
            $this->tagKey($key),
            $ttl,
            $callback
        );
    }

    public function invalidate(string $key): bool
    {
        return $this->cache->invalidate($this->tagKey($key));
    }

    public function flush(): bool
    {
        return $this->cache->flush($this->tags);
    }

    private function tagKey(string $key): string
    {
        return implode(':', array_merge($this->tags, [$key]));
    }
}

class IntegrityVerifier
{
    private string $secret;
    private array $config;

    public function __construct(string $secret, array $config)
    {
        $this->secret = $secret;
        $this->config = $config;
    }

    public function sign(string $key, mixed $value): string
    {
        return hash_hmac(
            'sha256',
            $this->serialize($key, $value),
            $this->secret
        );
    }

    public function verify(string $key, array $cached): bool
    {
        if (!isset($cached['data'], $cached['signature'], $cached['timestamp'])) {
            return false;
        }

        if (time() - $cached['timestamp'] > $this->config['max_age']) {
            return false;
        }

        $expectedSignature = $this->sign($key, $cached['data']);
        return hash_equals($expectedSignature, $cached['signature']);
    }

    private function serialize(string $key, mixed $value): string
    {
        return json_encode([
            'key' => $key,
            'value' => $value,
            'timestamp' => time()
        ]);
    }
}

class MetricsCollector
{
    private Redis $redis;
    private string $prefix;

    public function __construct(Redis $redis, string $prefix)
    {
        $this->redis = $redis;
        $this->prefix = $prefix;
    }

    public function recordHit(string $key, float $duration): void
    {
        $this->increment('hits');
        $this->recordDuration($key, $duration);
    }

    public function recordMiss(string $key, float $duration): void
    {
        $this->increment('misses');
        $this->recordDuration($key, $duration);
    }

    public function recordError(string $key, \Exception $e): void
    {
        $this->increment('errors');
        $this->redis->hIncrBy(
            "{$this->prefix}:errors:types",
            get_class($e),
            1
        );
    }

    private function increment(string $metric): void
    {
        $this->redis->incr("{$this->prefix}:{$metric}");
    }

    private function recordDuration(string $key, float $duration): void
    {
        $this->redis->hIncrByFloat(
            "{$this->prefix}:durations",
            $key,
            $duration
        );
    }
}
