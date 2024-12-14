<?php

namespace App\Core\Cache;

class CacheManager implements CacheManagerInterface
{
    private CacheStore $store;
    private Encryptor $encryptor;
    private ValidationService $validator;
    private AuditLogger $audit;
    private MetricsCollector $metrics;

    public function remember(string $key, callable $callback, int $ttl = 3600): mixed
    {
        try {
            $this->validateKey($key);
            
            $startTime = microtime(true);
            $value = $this->get($key);

            if ($value !== null) {
                $this->metrics->incrementCacheHit($key);
                return $this->decryptIfNeeded($value);
            }

            $value = $callback();
            $this->set($key, $value, $ttl);
            
            $this->metrics->recordCacheOperation(
                'remember',
                microtime(true) - $startTime
            );
            
            return $value;

        } catch (Exception $e) {
            $this->handleFailure($e, __FUNCTION__, $key);
            throw $e;
        }
    }

    public function set(string $key, mixed $value, int $ttl = 3600): bool
    {
        try {
            $this->validateKey($key);
            $this->validateValue($value);
            
            $encryptedValue = $this->encryptIfNeeded($value);
            $hash = $this->generateHash($encryptedValue);

            $metadata = [
                'hash' => $hash,
                'timestamp' => time(),
                'ttl' => $ttl
            ];

            $success = $this->store->set(
                $key,
                [
                    'data' => $encryptedValue,
                    'metadata' => $metadata
                ],
                $ttl
            );

            if ($success) {
                $this->audit->logCacheWrite($key, $metadata);
            }

            return $success;

        } catch (Exception $e) {
            $this->handleFailure($e, __FUNCTION__, $key);
            throw $e;
        }
    }

    public function get(string $key): mixed
    {
        try {
            $this->validateKey($key);
            
            $cached = $this->store->get($key);
            
            if ($cached === null) {
                $this->metrics->incrementCacheMiss($key);
                return null;
            }

            if (!$this->validateCache($cached)) {
                $this->handleInvalidCache($key, $cached);
                return null;
            }

            $this->metrics->incrementCacheHit($key);
            return $this->decryptIfNeeded($cached['data']);

        } catch (Exception $e) {
            $this->handleFailure($e, __FUNCTION__, $key);
            throw $e;
        }
    }

    public function forget(string $key): bool
    {
        try {
            $this->validateKey($key);
            
            $success = $this->store->forget($key);
            
            if ($success) {
                $this->audit->logCacheDelete($key);
            }
            
            return $success;

        } catch (Exception $e) {
            $this->handleFailure($e, __FUNCTION__, $key);
            throw $e;
        }
    }

    public function tags(array $tags): TaggedCache
    {
        return new TaggedCache(
            $this,
            $tags,
            $this->validator,
            $this->audit
        );
    }

    private function validateKey(string $key): void
    {
        if (!$this->validator->isValidCacheKey($key)) {
            throw new InvalidArgumentException('Invalid cache key format');
        }

        if (strlen($key) > 250) {
            throw new InvalidArgumentException('Cache key too long');
        }
    }

    private function validateValue(mixed $value): void
    {
        if (!is_serializable($value)) {
            throw new InvalidArgumentException('Cache value must be serializable');
        }
    }

    private function validateCache(array $cached): bool
    {
        if (!isset($cached['data'], $cached['metadata'])) {
            return false;
        }

        $metadata = $cached['metadata'];
        
        if (!$this->validateMetadata($metadata)) {
            return false;
        }

        $hash = $this->generateHash($cached['data']);
        return hash_equals($metadata['hash'], $hash);
    }

    private function validateMetadata(array $metadata): bool
    {
        $required = ['hash', 'timestamp', 'ttl'];
        
        foreach ($required as $field) {
            if (!isset($metadata[$field])) {
                return false;
            }
        }

        if ($metadata['timestamp'] + $metadata['ttl'] < time()) {
            return false;
        }

        return true;
    }

    private function encryptIfNeeded(mixed $value): mixed
    {
        if ($this->shouldEncrypt($value)) {
            return $this->encryptor->encrypt(serialize($value));
        }
        return $value;
    }

    private function decryptIfNeeded(mixed $value): mixed
    {
        if ($this->isEncrypted($value)) {
            return unserialize($this->encryptor->decrypt($value));
        }
        return $value;
    }

    private function shouldEncrypt(mixed $value): bool
    {
        return $this->containsSensitiveData($value) ||
               $this->isUserData($value) ||
               $this->isConfigured('encrypt_all');
    }

    private function isEncrypted(mixed $value): bool
    {
        return is_string($value) && 
               strlen($value) > 0 &&
               substr($value, 0, 4) === 'enc:';
    }

    private function generateHash(mixed $value): string
    {
        return hash_hmac(
            'sha256',
            is_string($value) ? $value : serialize($value),
            config('app.key')
        );
    }

    private function handleInvalidCache(string $key, array $cached): void
    {
        $this->audit->logCacheCorruption($key, $cached['metadata']);
        $this->forget($key);
        $this->metrics->incrementCorruptCache($key);
    }

    private function handleFailure(Exception $e, string $operation, string $key): void
    {
        $this->audit->logCacheError($e, [
            'operation' => $operation,
            'key' => $key,
            'trace' => $e->getTraceAsString()
        ]);
        
        $this->metrics->incrementFailureCount('cache_' . $operation);
    }
}
