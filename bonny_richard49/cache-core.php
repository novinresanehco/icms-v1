<?php

namespace App\Core\Cache;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Audit\AuditLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CacheManager implements CacheInterface
{
    private SecurityManagerInterface $security;
    private AuditLogger $auditLogger;
    private array $config;

    public function __construct(
        SecurityManagerInterface $security,
        AuditLogger $auditLogger,
        array $config
    ) {
        $this->security = $security;
        $this->auditLogger = $auditLogger;
        $this->config = $config;
    }

    public function remember(string $key, mixed $data, ?int $ttl = null): mixed
    {
        DB::beginTransaction();
        
        try {
            $this->validateCacheOperation($key);
            
            $cacheKey = $this->generateSecureCacheKey($key);
            $ttl = $ttl ?? $this->config['default_ttl'];
            
            if (Cache::has($cacheKey)) {
                $cached = Cache::get($cacheKey);
                if ($this->validateCachedData($cached)) {
                    $this->auditLogger->logCacheHit($key);
                    DB::commit();
                    return $cached;
                }
            }

            $value = $data instanceof \Closure ? $data() : $data;
            $encrypted = $this->security->encryptData($value);
            
            Cache::put($cacheKey, $encrypted, $ttl);
            $this->auditLogger->logCacheStore($key);
            
            DB::commit();
            return $value;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleCacheException($e, $key);
            throw $e;
        }
    }

    public function invalidate(string $key): void
    {
        DB::transaction(function() use ($key) {
            $cacheKey = $this->generateSecureCacheKey($key);
            Cache::forget($cacheKey);
            $this->auditLogger->logCacheInvalidation($key);
            
            $this->cleanupRelatedCache($key);
        });
    }

    public function flush(): void
    {
        DB::transaction(function() {
            Cache::flush();
            $this->auditLogger->logCacheFlush();
        });
    }

    public function validateCache(): CacheStatus
    {
        return DB::transaction(function() {
            $status = new CacheStatus();
            
            $status->addCheck('integrity', $this->validateCacheIntegrity());
            $status->addCheck('performance', $this->checkCachePerformance());
            $status->addCheck('security', $this->verifyCacheSecurity());
            
            $this->auditLogger->logCacheValidation($status);
            
            return $status;
        });
    }

    private function validateCacheOperation(string $key): void
    {
        if (!$this->security->validateCacheAccess($key)) {
            throw new CacheException('Invalid cache access attempt');
        }
    }

    private function generateSecureCacheKey(string $key): string
    {
        $prefix = $this->config['prefix'] ?? 'cms';
        $hash = hash_hmac('sha256', $key, $this->config['key']);
        return "{$prefix}:{$hash}";
    }

    private function validateCachedData(mixed $data): bool
    {
        try {
            $decrypted = $this->security->decryptData($data);
            return $this->security->verifyDataIntegrity($decrypted);
        } catch (\Exception) {
            return false;
        }
    }

    private function handleCacheException(\Exception $e, string $key): void
    {
        $this->auditLogger->logCacheError($key, $e);
        
        if ($this->isRecoverableError($e)) {
            $this->attemptCacheRecovery($key);
        }
    }

    private function cleanupRelatedCache(string $key): void
    {
        $pattern = $this->generateCachePattern($key);
        $keys = $this->findRelatedCacheKeys($pattern);
        
        foreach ($keys as $relatedKey) {
            Cache::forget($relatedKey);
        }
    }

    private function validateCacheIntegrity(): bool
    {
        $keys = $this->getAllCacheKeys();
        
        foreach ($keys as $key) {
            if (!$this->validateCacheKeyIntegrity($key)) {
                return false;
            }
        }
        
        return true;
    }

    private function checkCachePerformance(): array
    {
        return [
            'hit_rate' => $this->calculateHitRate(),
            'response_time' => $this->measureResponseTime(),
            'memory_usage' => $this->getMemoryUsage()
        ];
    }

    private function verifyCacheSecurity(): bool
    {
        $keys = $this->getAllCacheKeys();
        
        foreach ($keys as $key) {
            if (!$this->validateCacheSecurity($key)) {
                return false;
            }
        }
        
        return true;
    }

    private function isRecoverableError(\Exception $e): bool
    {
        return !($e instanceof CacheCorruptionException);
    }

    private function attemptCacheRecovery(string $key): void
    {
        $this->invalidate($key);
        $this->auditLogger->logCacheRecovery($key);
    }

    private function generateCachePattern(string $key): string
    {
        return sprintf(
            '%s:%s:*',
            $this->config['prefix'],
            explode(':', $key)[0]
        );
    }

    private function findRelatedCacheKeys(string $pattern): array
    {
        return Cache::getRedis()->keys($pattern);
    }

    private function getAllCacheKeys(): array
    {
        return Cache::getRedis()->keys($this->config['prefix'] . ':*');
    }

    private function validateCacheKeyIntegrity(string $key): bool
    {
        $value = Cache::get($key);
        return $value !== null && $this->validateCachedData($value);
    }

    private function validateCacheSecurity(string $key): bool
    {
        return $this->security->validateCacheKey($key);
    }

    private function calculateHitRate(): float
    {
        $stats = Cache::getRedis()->info();
        $hits = $stats['keyspace_hits'] ?? 0;
        $misses = $stats['keyspace_misses'] ?? 0;
        
        return $hits / ($hits + $misses);
    }

    private function measureResponseTime(): float
    {
        $start = microtime(true);
        Cache::get('benchmark_key');
        return microtime(true) - $start;
    }

    private function getMemoryUsage(): int
    {
        $info = Cache::getRedis()->info('memory');
        return $info['used_memory'] ?? 0;
    }
}
