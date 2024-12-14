namespace App\Core\Cache;

class CacheManager implements CacheManagerInterface
{
    private CacheStore $store;
    private SecurityManager $security;
    private AuditLogger $logger;
    private array $config;
    private array $locks = [];

    public function __construct(
        CacheStore $store,
        SecurityManager $security,
        AuditLogger $logger,
        ConfigRepository $config
    ) {
        $this->store = $store;
        $this->security = $security;
        $this->logger = $logger;
        $this->config = $config->get('cache');
    }

    public function get(string $key, $default = null)
    {
        $value = $this->store->get($this->getSecureKey($key));
        
        if ($value === null) {
            $this->logger->logCacheMiss($key);
            return $default;
        }

        $this->logger->logCacheHit($key);
        return $this->security->decryptData($value);
    }

    public function put(string $key, $value, int $ttl = null): bool
    {
        $secureKey = $this->getSecureKey($key);
        $encryptedValue = $this->security->encryptData($value);
        $ttl = $ttl ?? $this->config['default_ttl'];

        $success = $this->store->put($secureKey, $encryptedValue, $ttl);
        
        if ($success) {
            $this->logger->logCacheWrite($key, $ttl);
        }

        return $success;
    }

    public function remember(string $key, int $ttl, callable $callback)
    {
        if ($value = $this->get($key)) {
            return $value;
        }

        $value = $callback();
        $this->put($key, $value, $ttl);
        
        return $value;
    }

    public function rememberForever(string $key, callable $callback)
    {
        return $this->remember($key, $this->config['forever_ttl'], $callback);
    }

    public function forget(string $key): bool
    {
        $success = $this->store->forget($this->getSecureKey($key));
        
        if ($success) {
            $this->logger->logCacheDelete($key);
        }

        return $success;
    }

    public function flush(): bool
    {
        $success = $this->store->flush();
        
        if ($success) {
            $this->logger->logCacheFlush();
        }

        return $success;
    }

    public function tags(array $tags): TaggedCache
    {
        return new TaggedCache(
            $this->store->tags($tags),
            $this->security,
            $this->logger
        );
    }

    public function lock(string $key, int $timeout = 0): Lock
    {
        $lock = $this->store->lock($this->getSecureKey($key), $timeout);
        $this->locks[$key] = $lock;
        
        return $lock;
    }

    public function restoreLock(string $key): ?Lock
    {
        return $this->locks[$key] ?? null;
    }

    public function increment(string $key, $value = 1)
    {
        $secureKey = $this->getSecureKey($key);
        $result = $this->store->increment($secureKey, $value);
        
        if ($result !== false) {
            $this->logger->logCacheIncrement($key, $value);
        }

        return $result;
    }

    public function decrement(string $key, $value = 1)
    {
        $secureKey = $this->getSecureKey($key);
        $result = $this->store->decrement($secureKey, $value);
        
        if ($result !== false) {
            $this->logger->logCacheDecrement($key, $value);
        }

        return $result;
    }

    public function many(array $keys): array
    {
        $secureKeys = array_map(
            fn($key) => $this->getSecureKey($key),
            $keys
        );

        $values = $this->store->many($secureKeys);
        
        return array_map(
            fn($value) => $value === null ? null : $this->security->decryptData($value),
            $values
        );
    }

    public function putMany(array $values, int $ttl = null): bool
    {
        $secureValues = [];
        
        foreach ($values as $key => $value) {
            $secureValues[$this->getSecureKey($key)] = $this->security->encryptData($value);
        }

        $success = $this->store->putMany($secureValues, $ttl ?? $this->config['default_ttl']);
        
        if ($success) {
            $this->logger->logCacheWriteMany(array_keys($values));
        }

        return $success;
    }

    private function getSecureKey(string $key): string
    {
        return hash_hmac('sha256', $key, $this->config['key_salt']);
    }
}
