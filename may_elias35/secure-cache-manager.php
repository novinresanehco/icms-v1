namespace App\Core\Cache;

class SecureCacheManager implements CacheManagerInterface 
{
    private CacheStore $store;
    private EncryptionService $encryption;
    private ValidationService $validator;
    private MetricsCollector $metrics;
    private SecurityConfig $config;
    private AuditLogger $logger;

    public function __construct(
        CacheStore $store,
        EncryptionService $encryption,
        ValidationService $validator,
        MetricsCollector $metrics,
        SecurityConfig $config,
        AuditLogger $logger
    ) {
        $this->store = $store;
        $this->encryption = $encryption;
        $this->validator = $validator;
        $this->metrics = $metrics;
        $this->config = $config;
        $this->logger = $logger;
    }

    public function remember(string $key, $value, int $ttl = null): mixed
    {
        $startTime = microtime(true);
        
        try {
            // Generate secure cache key
            $secureKey = $this->generateSecureKey($key);
            
            // Check cache
            if ($cached = $this->get($secureKey)) {
                $this->metrics->incrementCacheHit($key);
                return $cached;
            }

            // Execute value resolver
            $result = value($value);
            
            // Validate result
            $this->validateCacheData($result);
            
            // Store with encryption
            $this->put($secureKey, $result, $ttl);
            
            $this->metrics->incrementCacheMiss($key);
            
            return $result;
            
        } catch (\Exception $e) {
            $this->handleCacheError($key, $e);
            throw $e;
        } finally {
            $this->metrics->recordCacheOperation(
                'remember',
                microtime(true) - $startTime
            );
        }
    }

    public function put(string $key, $value, int $ttl = null): bool
    {
        $startTime = microtime(true);
        
        try {
            // Validate input
            $this->validateCacheData($value);
            
            // Generate secure key
            $secureKey = $this->generateSecureKey($key);
            
            // Encrypt value
            $encrypted = $this->encryptCacheData($value);
            
            // Add metadata
            $data = $this->addMetadata($encrypted);
            
            // Store with integrity check
            $success = $this->store->put(
                $secureKey,
                $data,
                $ttl ?? $this->config->get('cache.ttl')
            );
            
            if ($success) {
                $this->logger->logCacheOperation('put', $key);
            }
            
            return $success;
            
        } catch (\Exception $e) {
            $this->handleCacheError($key, $e);
            return false;
        } finally {
            $this->metrics->recordCacheOperation(
                'put',
                microtime(true) - $startTime
            );
        }
    }

    public function get(string $key): mixed
    {
        $startTime = microtime(true);
        
        try {
            // Generate secure key
            $secureKey = $this->generateSecureKey($key);
            
            // Get encrypted data
            $data = $this->store->get($secureKey);
            
            if ($data === null) {
                return null;
            }
            
            // Verify integrity
            if (!$this->verifyIntegrity($data)) {
                $this->handleIntegrityFailure($key);
                return null;
            }
            
            // Extract and decrypt value
            $decrypted = $this->decryptCacheData($data['value']);
            
            // Validate decrypted data
            $this->validateCacheData($decrypted);
            
            return $decrypted;
            
        } catch (\Exception $e) {
            $this->handleCacheError($key, $e);
            return null;
        } finally {
            $this->metrics->recordCacheOperation(
                'get',
                microtime(true) - $startTime
            );
        }
    }

    public function forget(string $key): bool
    {
        $startTime = microtime(true);
        
        try {
            $secureKey = $this->generateSecureKey($key);
            
            $success = $this->store->forget($secureKey);
            
            if ($success) {
                $this->logger->logCacheOperation('forget', $key);
            }
            
            return $success;
            
        } catch (\Exception $e) {
            $this->handleCacheError($key, $e);
            return false;
        } finally {
            $this->metrics->recordCacheOperation(
                'forget',
                microtime(true) - $startTime
            );
        }
    }

    protected function generateSecureKey(string $key): string
    {
        return hash_hmac(
            'sha256',
            $key,
            $this->config->get('cache.key_salt')
        );
    }

    protected function encryptCacheData($data): array
    {
        $encrypted = $this->encryption->encrypt(serialize($data));
        
        return [
            'value' => $encrypted,
            'hash' => $this->generateHash($encrypted),
            'metadata' => $this->getMetadata()
        ];
    }

    protected function decryptCacheData(string $encrypted): mixed
    {
        return unserialize(
            $this->encryption->decrypt($encrypted)
        );
    }

    protected function validateCacheData($data): void
    {
        if (!$this->validator->validateCacheData($data)) {
            throw new InvalidCacheDataException();
        }
    }

    protected function verifyIntegrity(array $data): bool
    {
        return hash_equals(
            $data['hash'],
            $this->generateHash($data['value'])
        );
    }

    protected function generateHash(string $data): string
    {
        return hash_hmac(
            'sha256',
            $data,
            $this->config->get('cache.hash_key')
        );
    }

    protected function handleCacheError(string $key, \Exception $e): void
    {
        $this->logger->logError('cache_operation_failed', [
            'key' => $key,
            'error' => $e->getMessage()
        ]);
        
        $this->metrics->incrementCacheError($key);
    }

    protected function handleIntegrityFailure(string $key): void
    {
        $this->logger->logSecurityEvent('cache_integrity_failed', [
            'key' => $key
        ]);
        
        $this->forget($key);
        
        $this->metrics->incrementCacheIntegrityFailure($key);
    }
}
