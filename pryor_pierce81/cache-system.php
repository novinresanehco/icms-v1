namespace App\Core\Cache;

class CacheManager implements CacheInterface
{
    private CacheStore $store;
    private SecurityManager $security;
    private EncryptionService $encryption;
    private ValidationService $validator;
    private MetricsCollector $metrics;
    private AuditLogger $logger;

    public function remember(string $key, $value, int $ttl = 3600): mixed
    {
        try {
            $this->validateKey($key);
            
            if ($cached = $this->get($key)) {
                $this->metrics->increment('cache.hits');
                return $cached;
            }

            $this->metrics->increment('cache.misses');
            
            $value = $value instanceof \Closure ? $value() : $value;
            $validated = $this->validator->validateData($value);
            $encrypted = $this->encryption->encrypt(serialize($validated));
            
            $this->store->put($key, $encrypted, $ttl);
            
            $this->logger->log('cache.stored', [
                'key' => $key,
                'ttl' => $ttl,
                'size' => strlen($encrypted)
            ]);

            return $value;

        } catch (\Exception $e) {
            $this->handleError($key, $e);
            return $value instanceof \Closure ? $value() : $value;
        }
    }

    public function get(string $key): mixed
    {
        try {
            $this->validateKey($key);
            
            if (!$encrypted = $this->store->get($key)) {
                return null;
            }

            $data = unserialize($this->encryption->decrypt($encrypted));
            
            if (!$this->validator->validateData($data)) {
                $this->logger->warning('cache.invalid_data', ['key' => $key]);
                $this->forget($key);
                return null;
            }

            $this->metrics->increment('cache.hits');
            return $data;

        } catch (\Exception $e) {
            $this->handleError($key, $e);
            return null;
        }
    }

    public function put(string $key, $value, int $ttl = 3600): bool
    {
        return $this->security->executeCriticalOperation(
            new CacheOperation(function() use ($key, $value, $ttl) {
                $this->validateKey($key);
                $validated = $this->validator->validateData($value);
                $encrypted = $this->encryption->encrypt(serialize($validated));
                
                $success = $this->store->put($key, $encrypted, $ttl);
                
                if ($success) {
                    $this->metrics->increment('cache.writes');
                    $this->logger->log('cache.stored', [
                        'key' => $key,
                        'ttl' => $ttl
                    ]);
                }

                return $success;
            })
        );
    }

    public function forget(string $key): bool
    {
        return $this->security->executeCriticalOperation(
            new CacheOperation(function() use ($key) {
                $this->validateKey($key);
                
                $forgotten = $this->store->forget($key);
                
                if ($forgotten) {
                    $this->metrics->increment('cache.deletes');
                    $this->logger->log('cache.forgotten', ['key' => $key]);
                }

                return $forgotten;
            })
        );
    }

    public function tags(array $tags): TaggedCache
    {
        array_walk($tags, [$this, 'validateKey']);
        return new TaggedCache($this, $tags);
    }

    public function flush(): bool
    {
        return $this->security->executeCriticalOperation(
            new CacheOperation(function() {
                $flushed = $this->store->flush();
                
                if ($flushed) {
                    $this->metrics->increment('cache.flushes');
                    $this->logger->log('cache.flushed');
                }

                return $flushed;
            })
        );
    }

    public function getMultiple(array $keys): array
    {
        $result = [];
        
        foreach ($keys as $key) {
            $result[$key] = $this->get($key);
        }

        return $result;
    }

    public function putMultiple(array $values, int $ttl = 3600): bool
    {
        return $this->security->executeCriticalOperation(
            new CacheOperation(function() use ($values, $ttl) {
                $success = true;
                
                foreach ($values as $key => $value) {
                    if (!$this->put($key, $value, $ttl)) {
                        $success = false;
                    }
                }

                return $success;
            })
        );
    }

    private function validateKey(string $key): void
    {
        if (!preg_match('/^[a-zA-Z0-9:._-]+$/', $key)) {
            throw new InvalidCacheKeyException();
        }
    }

    private function handleError(string $key, \Exception $e): void
    {
        $this->metrics->increment('cache.errors');
        $this->logger->error('cache.error', [
            'key' => $key,
            'error' => $e->getMessage()
        ]);
    }
}
