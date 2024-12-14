<?php

namespace App\Core\Cache;

use Illuminate\Support\Facades\{Cache, DB, Log};
use App\Core\Security\SecurityManager;
use App\Core\Security\DataProtection\DataProtectionService;
use App\Core\Cache\Events\CacheEvent;
use App\Core\Cache\Exceptions\{CacheException, SecurityException};

class SecureCacheManager implements CacheManagerInterface
{
    private SecurityManager $security;
    private DataProtectionService $protection;
    private CacheValidator $validator;
    private SecurityAudit $audit;
    private array $config;

    private const CACHE_VERSION = 'v1';
    private const MAX_LOCK_ATTEMPTS = 3;
    private const LOCK_TIMEOUT = 5;

    public function __construct(
        SecurityManager $security,
        DataProtectionService $protection,
        CacheValidator $validator,
        SecurityAudit $audit,
        array $config
    ) {
        $this->security = $security;
        $this->protection = $protection;
        $this->validator = $validator;
        $this->audit = $audit;
        $this->config = $config;
    }

    public function secureGet(string $key, array $context = []): CacheResult
    {
        try {
            $this->validateAccess($key, $context);
            $secureKey = $this->generateSecureKey($key, $context);
            
            if ($data = Cache::get($secureKey)) {
                $decrypted = $this->decryptCacheData($data);
                
                if (!$this->validator->validateCacheData($decrypted, $context)) {
                    $this->handleInvalidCache($secureKey, $context);
                    return new CacheResult(null, false);
                }
                
                $this->audit->logCacheHit($secureKey, $context);
                return new CacheResult($decrypted, true);
            }
            
            $this->audit->logCacheMiss($secureKey, $context);
            return new CacheResult(null, false);
            
        } catch (\Exception $e) {
            $this->handleCacheFailure($e, $key, $context);
            throw $e;
        }
    }

    public function secureSet(string $key, $value, array $context = []): bool
    {
        return $this->executeWithLock($key, function() use ($key, $value, $context) {
            try {
                $this->validateData($value, $context);
                $secureKey = $this->generateSecureKey($key, $context);
                
                $encrypted = $this->encryptCacheData($value);
                $metadata = $this->generateCacheMetadata($context);
                
                $success = Cache::put(
                    $secureKey,
                    $this->prepareCachePayload($encrypted, $metadata),
                    $this->calculateTTL($context)
                );
                
                if ($success) {
                    $this->audit->logCacheSet($secureKey, $context);
                    $this->updateCacheIndex($secureKey, $metadata);
                }
                
                return $success;
                
            } catch (\Exception $e) {
                $this->handleCacheFailure($e, $key, $context);
                throw $e;
            }
        });
    }

    public function secureRemove(string $key, array $context = []): bool
    {
        return $this->executeWithLock($key, function() use ($key, $context) {
            try {
                $this->validateAccess($key, $context);
                $secureKey = $this->generateSecureKey($key, $context);
                
                if (Cache::forget($secureKey)) {
                    $this->audit->logCacheRemoval($secureKey, $context);
                    $this->removeCacheIndex($secureKey);
                    return true;
                }
                
                return false;
                
            } catch (\Exception $e) {
                $this->handleCacheFailure($e, $key, $context);
                throw $e;
            }
        });
    }

    public function refreshCache(array $patterns, array $context = []): void
    {
        try {
            foreach ($patterns as $pattern) {
                $keys = $this->findCacheKeys($pattern);
                
                foreach ($keys as $key) {
                    if ($this->requiresRefresh($key, $context)) {
                        $this->refreshCacheEntry($key, $context);
                    }
                }
            }
            
            $this->audit->logCacheRefresh($patterns, $context);
            
        } catch (\Exception $e) {
            $this->handleCacheFailure($e, implode(',', $patterns), $context);
            throw $e;
        }
    }

    protected function validateAccess(string $key, array $context): void
    {
        if (!$this->security->hasAccess('cache.access', $context)) {
            throw new SecurityException('Cache access denied');
        }
    }

    protected function validateData($value, array $context): void
    {
        if (!$this->validator->validateData($value, $context)) {
            throw new CacheException('Invalid cache data');
        }
    }

    protected function generateSecureKey(string $key, array $context): string
    {
        return sprintf(
            '%s:%s:%s:%s',
            self::CACHE_VERSION,
            $this->security->getCurrentContext()['environment'],
            hash('sha256', $key),
            $this->generateContextHash($context)
        );
    }

    protected function encryptCacheData($data): string
    {
        return $this->protection->encrypt(serialize($data));
    }

    protected function decryptCacheData(string $encrypted): mixed
    {
        return unserialize($this->protection->decrypt($encrypted));
    }

    protected function generateCacheMetadata(array $context): array
    {
        return [
            'created_at' => now()->toIso8601String(),
            'created_by' => auth()->id(),
            'context_hash' => $this->generateContextHash($context),
            'security_level' => $this->calculateSecurityLevel($context)
        ];
    }

    protected function prepareCachePayload($encrypted, array $metadata): array
    {
        return [
            'data' => $encrypted,
            'metadata' => $metadata,
            'hash' => $this->generatePayloadHash($encrypted, $metadata)
        ];
    }

    protected function calculateTTL(array $context): int
    {
        return $context['ttl'] ?? $this->config['default_ttl'];
    }

    protected function executeWithLock(string $key, callable $operation)
    {
        $lockKey = "cache_lock:$key";
        $attempts = 0;
        
        while ($attempts < self::MAX_LOCK_ATTEMPTS) {
            if (Cache::lock($lockKey, self::LOCK_TIMEOUT)->get()) {
                try {
                    return $operation();
                } finally {
                    Cache::lock($lockKey)->release();
                }
            }
            $attempts++;
            usleep(100000 * $attempts);
        }
        
        throw new CacheException('Failed to acquire cache lock');
    }

    private function generateContextHash(array $context): string
    {
        return hash('sha256', serialize($context));
    }

    private function calculateSecurityLevel(array $context): int
    {
        return $context['security_level'] ?? SecurityLevel::STANDARD;
    }

    private function generatePayloadHash($encrypted, array $metadata): string
    {
        return hash('sha256', $encrypted . serialize($metadata));
    }
}
