namespace App\Core\Cache;

class CacheManager implements CacheInterface
{
    private Store $store;
    private SecurityManager $security;
    private MonitoringService $monitor;
    private ValidationService $validator;
    private array $config;

    public function remember(string $key, mixed $value, ?int $ttl = null): mixed
    {
        $cacheKey = $this->generateSecureKey($key);
        
        return $this->executeWithMonitoring($cacheKey, function() use ($cacheKey, $value, $ttl) {
            if ($cached = $this->get($cacheKey)) {
                $this->monitor->recordHit($cacheKey);
                return $this->validateCachedData($cached);
            }

            $computed = is_callable($value) ? $value() : $value;
            $validated = $this->validateDataForCache($computed);
            
            $this->set($cacheKey, $validated, $ttl ?? $this->config['default_ttl']);
            $this->monitor->recordMiss($cacheKey);
            
            return $validated;
        });
    }

    public function tags(array $tags): self
    {
        return new static(
            $this->store->tags($this->validateTags($tags)),
            $this->security,
            $this->monitor,
            $this->validator,
            $this->config
        );
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $cacheKey = $this->generateSecureKey($key);
        
        return $this->executeWithMonitoring($cacheKey, function() use ($cacheKey, $default) {
            $value = $this->store->get($cacheKey);
            
            if ($value === null) {
                $this->monitor->recordMiss($cacheKey);
                return $default;
            }

            $this->monitor->recordHit($cacheKey);
            return $this->validateCachedData($value);
        });
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $cacheKey = $this->generateSecureKey($key);
        $validated = $this->validateDataForCache($value);
        
        return $this->executeWithMonitoring($cacheKey, function() use ($cacheKey, $validated, $ttl) {
            $success = $this->store->set(
                $cacheKey, 
                $validated,
                $ttl ?? $this->config['default_ttl']
            );

            if ($success) {
                $this->monitor->recordSet($cacheKey);
            }

            return $success;
        });
    }

    public function delete(string $key): bool
    {
        $cacheKey = $this->generateSecureKey($key);
        
        return $this->executeWithMonitoring($cacheKey, function() use ($cacheKey) {
            $success = $this->store->delete($cacheKey);
            
            if ($success) {
                $this->monitor->recordDelete($cacheKey);
            }

            return $success;
        });
    }

    public function clear(): bool
    {
        return $this->executeWithMonitoring('full_clear', function() {
            $success = $this->store->clear();
            
            if ($success) {
                $this->monitor->recordClear();
            }

            return $success;
        });
    }

    protected function generateSecureKey(string $key): string
    {
        return hash_hmac(
            'sha256',
            $key,
            $this->config['key_salt']
        );
    }

    protected function validateDataForCache(mixed $data): mixed
    {
        if (!$this->validator->isSerializable($data)) {
            throw new CacheException('Data cannot be serialized for caching');
        }

        if ($this->exceedsMaxSize($data)) {
            throw new CacheException('Data exceeds maximum cache size');
        }

        return $data;
    }

    protected function validateCachedData(mixed $data): mixed
    {
        if (!$this->validator->isValid($data)) {
            $this->monitor->recordCorruption($data);
            throw new CacheException('Cached data validation failed');
        }

        return $data;
    }

    protected function validateTags(array $tags): array
    {
        return array_map(function($tag) {
            if (!$this->validator->isValidTag($tag)) {
                throw new CacheException('Invalid cache tag');
            }
            return $tag;
        }, $tags);
    }

    protected function executeWithMonitoring(string $key, callable $operation): mixed
    {
        $start = microtime(true);
        
        try {
            $result = $operation();
            $this->monitor->recordSuccess($key, microtime(true) - $start);
            return $result;
        } catch (\Exception $e) {
            $this->monitor->recordFailure($key, $e);
            throw $e;
        }
    }

    protected function exceedsMaxSize(mixed $data): bool
    {
        return strlen(serialize($data)) > $this->config['max_size'];
    }
}
