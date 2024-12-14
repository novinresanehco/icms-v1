<?php

namespace App\Core\Cache;

use Illuminate\Support\Facades\{Cache, Redis, Log};
use App\Core\Security\SecurityManager;
use App\Core\Services\{ValidationService, HashService};
use App\Core\Exceptions\{CacheException, SecurityException};

class CacheManager implements CacheInterface
{
    private SecurityManager $security;
    private ValidationService $validator;
    private HashService $hash;
    
    private const DEFAULT_TTL = 3600;
    private const MAX_KEY_LENGTH = 250;
    private const LOCK_TIMEOUT = 5;
    private const MEMORY_THRESHOLD = 80;

    private array $tags = [];
    private array $locks = [];

    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        HashService $hash
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->hash = $hash;
    }

    public function remember(string $key, $data, ?int $ttl = null): mixed
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeRemember($key, $data, $ttl),
            ['action' => 'cache.remember', 'key' => $key]
        );
    }

    protected function executeRemember(string $key, $data, ?int $ttl): mixed
    {
        $this->validateKey($key);
        $this->checkMemoryUsage();

        $key = $this->normalizeKey($key);
        $ttl = $ttl ?? self::DEFAULT_TTL;

        try {
            $lock = $this->acquireLock($key);

            $cached = Cache::get($key);
            if ($cached !== null) {
                $this->releaseLock($lock);
                return $cached;
            }

            $value = is_callable($data) ? $data() : $data;
            
            $this->store($key, $value, $ttl);
            
            $this->releaseLock($lock);

            return $value;

        } catch (\Exception $e) {
            if (isset($lock)) {
                $this->releaseLock($lock);
            }
            throw new CacheException('Cache operation failed: ' . $e->getMessage());
        }
    }

    public function tags(array $tags): self
    {
        $this->validateTags($tags);
        $this->tags = array_merge($this->tags, $tags);
        return $this;
    }

    public function flush(array $tags = []): bool
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeFlush($tags),
            ['action' => 'cache.flush', 'tags' => $tags]
        );
    }

    protected function executeFlush(array $tags): bool
    {
        try {
            if (empty($tags)) {
                Cache::flush();
                return true;
            }

            foreach ($tags as $tag) {
                $this->flushTag($tag);
            }

            return true;

        } catch (\Exception $e) {
            throw new CacheException('Cache flush failed: ' . $e->getMessage());
        }
    }

    protected function store(string $key, $value, int $ttl): void
    {
        $metadata = [
            'timestamp' => time(),
            'ttl' => $ttl,
            'tags' => $this->tags,
            'checksum' => $this->hash->generateHash($value)
        ];

        $stored = [
            'value' => $value,
            'metadata' => $metadata
        ];

        if (!empty($this->tags)) {
            Cache::tags($this->tags)->put($key, $stored, $ttl);
            $this->updateTagIndex($key);
        } else {
            Cache::put($key, $stored, $ttl);
        }
    }

    protected function validateKey(string $key): void
    {
        if (strlen($key) > self::MAX_KEY_LENGTH) {
            throw new CacheException('Cache key exceeds maximum length');
        }

        if (!preg_match('/^[a-zA-Z0-9:._-]+$/', $key)) {
            throw new CacheException('Invalid cache key format');
        }
    }

    protected function validateTags(array $tags): void
    {
        foreach ($tags as $tag) {
            if (!is_string($tag) || !preg_match('/^[a-zA-Z0-9_-]+$/', $tag)) {
                throw new CacheException('Invalid tag format');
            }
        }
    }

    protected function normalizeKey(string $key): string
    {
        return strtolower(trim($key));
    }

    protected function checkMemoryUsage(): void
    {
        $memoryUsage = $this->getMemoryUsage();
        
        if ($memoryUsage > self::MEMORY_THRESHOLD) {
            $this->handleHighMemoryUsage($memoryUsage);
        }
    }

    protected function getMemoryUsage(): float
    {
        $info = Redis::info('memory');
        $used = $info['used_memory'];
        $total = $info['maxmemory'];
        
        return ($used / $total) * 100;
    }

    protected function handleHighMemoryUsage(float $usage): void
    {
        Log::warning('High cache memory usage detected', [
            'usage_percentage' => $usage,
            'threshold' => self::MEMORY_THRESHOLD
        ]);

        // Implement eviction strategy
        $this->evictStaleEntries();
    }

    protected function evictStaleEntries(): void
    {
        $keys = Redis::keys('*');
        
        foreach ($keys as $key) {
            $ttl = Redis::ttl($key);
            
            if ($ttl <= 0 || $this->isStaleEntry($key)) {
                Redis::del($key);
            }
        }
    }

    protected function isStaleEntry(string $key): bool
    {
        $value = Cache::get($key);
        
        if (!isset($value['metadata'])) {
            return true;
        }

        $metadata = $value['metadata'];
        $age = time() - $metadata['timestamp'];
        
        return $age > ($metadata['ttl'] * 1.5);
    }

    protected function acquireLock(string $key): string
    {
        $lockKey = "lock:{$key}";
        $lockId = uniqid('', true);

        $acquired = Redis::set(
            $lockKey,
            $lockId,
            'NX',
            'EX',
            self::LOCK_TIMEOUT
        );

        if (!$acquired) {
            throw new CacheException('Failed to acquire cache lock');
        }

        $this->locks[$lockKey] = $lockId;
        return $lockKey;
    }

    protected function releaseLock(string $lockKey): void
    {
        if (!isset($this->locks[$lockKey])) {
            return;
        }

        $lockId = $this->locks[$lockKey];
        
        Redis::eval(
            "if redis.call('get', KEYS[1]) == ARGV[1] then
                return redis.call('del', KEYS[1])
            else
                return 0
            end",
            1,
            $lockKey,
            $lockId
        );

        unset($this->locks[$lockKey]);
    }

    protected function updateTagIndex(string $key): void
    {
        foreach ($this->tags as $tag) {
            $tagKey = "tag:{$tag}";
            Redis::sadd($tagKey, $key);
            Redis::expire($tagKey, self::DEFAULT_TTL);
        }
    }

    protected function flushTag(string $tag): void
    {
        $tagKey = "tag:{$tag}";
        $keys = Redis::smembers($tagKey);
        
        if (!empty($keys)) {
            Redis::del(...$keys);
            Redis::del($tagKey);
        }
    }

    public function __destruct()
    {
        // Release any remaining locks
        foreach ($this->locks as $lockKey => $lockId) {
            $this->releaseLock($lockKey);
        }
    }
}
