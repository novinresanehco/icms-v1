<?php

namespace App\Core\Cache;

use Illuminate\Support\Facades\{Cache, Redis, Log};
use App\Core\Security\SecurityManager;
use App\Core\Exceptions\CacheException;

class CacheManager implements CacheInterface
{
    protected SecurityManager $security;
    protected array $config;
    private array $locks = [];

    public function __construct(SecurityManager $security, array $config)
    {
        $this->security = $security;
        $this->config = $config;
    }

    public function remember(string $key, callable $callback, ?int $ttl = null): mixed
    {
        try {
            $this->acquireLock($key);
            
            if ($value = $this->get($key)) {
                return $this->validateAndReturn($value);
            }

            $value = $callback();
            $this->set($key, $value, $ttl);
            
            return $value;
            
        } finally {
            $this->releaseLock($key);
        }
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        return $this->security->executeCriticalOperation(function() use ($key, $value, $ttl) {
            $stored = [
                'data' => $value,
                'hash' => $this->hashData($value),
                'timestamp' => now()->timestamp
            ];
            
            return Cache::put(
                $this->prefixKey($key),
                $this->encrypt($stored),
                $ttl ?? $this->config['default_ttl']
            );
        });
    }

    public function get(string $key): mixed
    {
        $encrypted = Cache::get($this->prefixKey($key));
        
        if (!$encrypted) {
            return null;
        }

        try {
            $stored = $this->decrypt($encrypted);
            return $this->validateAndReturn($stored);
        } catch (\Exception $e) {
            $this->handleCorruptedCache($key, $e);
            return null;
        }
    }

    public function tags(array $tags): self
    {
        Cache::tags($tags);
        return $this;
    }

    public function flush(array $tags = []): bool
    {
        return $this->security->executeCriticalOperation(function() use ($tags) {
            if (empty($tags)) {
                return Cache::flush();
            }
            return Cache::tags($tags)->flush();
        });
    }

    protected function prefixKey(string $key): string
    {
        return "{$this->config['prefix']}:{$key}";
    }

    protected function acquireLock(string $key): void
    {
        $lockKey = "lock:{$this->prefixKey($key)}";
        $lock = Redis::lock($lockKey, 10);
        
        if (!$lock->get()) {
            throw new CacheException("Could not acquire lock for key: {$key}");
        }
        
        $this->locks[$key] = $lock;
    }

    protected function releaseLock(string $key): void
    {
        if (isset($this->locks[$key])) {
            $this->locks[$key]->release();
            unset($this->locks[$key]);
        }
    }

    protected function encrypt(array $data): string
    {
        return encrypt($data);
    }

    protected function decrypt(string $encrypted): array
    {
        return decrypt($encrypted);
    }

    protected function hashData(mixed $data): string
    {
        return hash('sha256', serialize($data));
    }

    protected function validateAndReturn(array $stored): mixed
    {
        if (!isset($stored['data'], $stored['hash'], $stored['timestamp'])) {
            throw new CacheException('Invalid cache structure');
        }

        if ($this->hashData($stored['data']) !== $stored['hash']) {
            throw new CacheException('Cache data corruption detected');
        }

        if ($stored['timestamp'] + $this->config['max_age'] < now()->timestamp) {
            throw new CacheException('Cache data expired');
        }

        return $stored['data'];
    }

    protected function handleCorruptedCache(string $key, \Exception $e): void
    {
        Log::error('Cache corruption detected', [
            'key' => $key,
            'exception' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        try {
            Cache::forget($this->prefixKey($key));
        } catch (\Exception $e) {
            Log::error('Failed to clear corrupted cache', [
                'key' => $key,
                'exception' => $e->getMessage()
            ]);
        }
    }
}
