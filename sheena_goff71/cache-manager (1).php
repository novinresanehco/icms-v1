<?php

namespace App\Core\Cache;

use Illuminate\Support\Facades\{Cache, Log};
use App\Core\Security\{SecurityManager, Encryption};
use App\Core\Services\{ValidationService, AuditService};

class CacheManager implements CacheInterface 
{
    private SecurityManager $security;
    private ValidationService $validator;
    private AuditService $auditor;
    private Encryption $encryption;
    private array $config;
    private array $metrics = [];

    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        AuditService $auditor,
        Encryption $encryption,
        array $config
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->auditor = $auditor;
        $this->encryption = $encryption;
        $this->config = $config;
    }

    public function remember(string $key, array $context, callable $callback): mixed 
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeRemember($key, $callback),
            $this->buildCacheContext('remember', $context, $key)
        );
    }

    public function store(string $key, mixed $value, array $context): bool 
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeStore($key, $value),
            $this->buildCacheContext('store', $context, $key)
        );
    }

    public function retrieve(string $key, array $context): mixed 
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeRetrieve($key),
            $this->buildCacheContext('retrieve', $context, $key)
        );
    }

    public function invalidate(string $key, array $context): bool 
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeInvalidate($key),
            $this->buildCacheContext('invalidate', $context, $key)
        );
    }

    protected function executeRemember(string $key, callable $callback): mixed 
    {
        $this->validateCacheKey($key);
        $this->trackCacheOperation('remember', $key);

        $value = Cache::get($this->getSecureKey($key));
        
        if ($value !== null) {
            $this->recordCacheHit($key);
            return $this->decryptIfNeeded($value);
        }

        $this->recordCacheMiss($key);
        $value = $callback();
        
        $this->executeStore($key, $value);
        return $value;
    }

    protected function executeStore(string $key, mixed $value): bool 
    {
        $this->validateCacheKey($key);
        $this->validateCacheValue($value);
        $this->trackCacheOperation('store', $key);

        $encrypted = $this->encryptIfNeeded($value);
        $ttl = $this->calculateTTL($key);

        try {
            Cache::put(
                $this->getSecureKey($key),
                $encrypted,
                now()->addSeconds($ttl)
            );
            
            $this->recordCacheStore($key);
            return true;
            
        } catch (\Exception $e) {
            $this->handleCacheError('store', $key, $e);
            return false;
        }
    }

    protected function executeRetrieve(string $key): mixed 
    {
        $this->validateCacheKey($key);
        $this->trackCacheOperation('retrieve', $key);

        $value = Cache::get($this->getSecureKey($key));
        
        if ($value === null) {
            $this->recordCacheMiss($key);
            return null;
        }

        $this->recordCacheHit($key);
        return $this->decryptIfNeeded($value);
    }

    protected function executeInvalidate(string $key): bool 
    {
        $this->validateCacheKey($key);
        $this->trackCacheOperation('invalidate', $key);

        try {
            Cache::forget($this->getSecureKey($key));
            $this->recordCacheInvalidation($key);
            return true;
            
        } catch (\Exception $e) {
            $this->handleCacheError('invalidate', $key, $e);
            return false;
        }
    }

    protected function validateCacheKey(string $key): void 
    {
        if (!$this->validator->validateCacheKey($key)) {
            throw new CacheValidationException('Invalid cache key format');
        }
    }

    protected function validateCacheValue(mixed $value): void 
    {
        if (!$this->validator->validateCacheValue($value)) {
            throw new CacheValidationException('Invalid cache value format');
        }
    }

    protected function getSecureKey(string $key): string 
    {
        return hash_hmac('sha256', $key, $this->config['key_salt']);
    }

    protected function encryptIfNeeded(mixed $value): mixed 
    {
        if ($this->shouldEncrypt($value)) {
            return $this->encryption->encrypt(serialize($value));
        }
        return $value;
    }

    protected function decryptIfNeeded(mixed $value): mixed 
    {
        if ($this->isEncrypted($value)) {
            return unserialize($this->encryption->decrypt($value));
        }
        return $value;
    }

    protected function shouldEncrypt(mixed $value): bool 
    {
        return $this->config['encrypt_values'] || 
               $this->containsSensitiveData($value);
    }

    protected function isEncrypted(mixed $value): bool 
    {
        return is_string($value) && 
               str_starts_with($value, $this->config['encryption_prefix']);
    }

    protected function calculateTTL(string $key): int 
    {
        foreach ($this->config['ttl_rules'] as $pattern => $ttl) {
            if (preg_match($pattern, $key)) {
                return $ttl;
            }
        }
        return $this->config['default_ttl'];
    }

    protected function buildCacheContext(string $operation, array $context, string $key): array 
    {
        return array_merge($context, [
            'operation' => $operation,
            'cache_key' => $key,
            'timestamp' => microtime(true)
        ]);
    }

    protected function trackCacheOperation(string $operation, string $key): void 
    {
        $this->metrics[$operation] = ($this->metrics[$operation] ?? 0) + 1;
        
        if ($this->shouldLogOperation($operation)) {
            $this->auditor->logCacheOperation($operation, $key);
        }
    }

    protected function recordCacheHit(string $key): void 
    {
        $this->metrics['hits'] = ($this->metrics['hits'] ?? 0) + 1;
        $this->updateHitRatio();
    }

    protected function recordCacheMiss(string $key): void 
    {
        $this->metrics['misses'] = ($this->metrics['misses'] ?? 0) + 1;
        $this->updateHitRatio();
    }

    protected function recordCacheStore(string $key): void 
    {
        $this->metrics['stores'] = ($this->metrics['stores'] ?? 0) + 1;
    }

    protected function recordCacheInvalidation(string $key): void 
    {
        $this->metrics['invalidations'] = ($this->metrics['invalidations'] ?? 0) + 1;
    }

    protected function updateHitRatio(): void 
    {
        $total = ($this->metrics['hits'] ?? 0) + ($this->metrics['misses'] ?? 0);
        if ($total > 0) {
            $this->metrics['hit_ratio'] = ($this->metrics['hits'] ?? 0) / $total;
        }
    }

    protected function handleCacheError(string $operation, string $key, \Exception $e): void 
    {
        $this->auditor->logCacheError($operation, $key, $e);
        $this->metrics['errors'] = ($this->metrics['errors'] ?? 0) + 1;
    }

    protected function shouldLogOperation(string $operation): bool 
    {
        return in_array($operation, $this->config['logged_operations']);
    }

    protected function containsSensitiveData(mixed $value): bool 
    {
        if (!is_array($value)) {
            return false;
        }
        
        foreach ($this->config['sensitive_fields'] as $field) {
            if (array_key_exists($field, $value)) {
                return true;
            }
        }
        return false;
    }
}

class CacheValidationException extends \RuntimeException {}
