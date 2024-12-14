<?php
namespace App\Core\Cache;

use Illuminate\Support\Facades\{Cache, Log};
use App\Core\Security\{SecurityManager, EncryptionService};
use App\Core\Exceptions\{CacheException, SecurityException};

class CacheManager implements CacheManagerInterface
{
    private SecurityManager $security;
    private EncryptionService $encryption;
    private ValidationService $validator;
    private AuditLogger $audit;

    public function remember(string $key, $value, SecurityContext $context, ?int $ttl = null): mixed
    {
        return $this->security->executeCriticalOperation(function() use ($key, $value, $ttl) {
            $cacheKey = $this->generateSecureKey($key);
            
            if (Cache::has($cacheKey)) {
                return $this->getFromCache($cacheKey);
            }

            $data = is_callable($value) ? $value() : $value;
            $this->storeInCache($cacheKey, $data, $ttl);
            
            return $data;
        }, $context);
    }

    public function set(string $key, $value, SecurityContext $context, ?int $ttl = null): bool
    {
        return $this->security->executeCriticalOperation(function() use ($key, $value, $ttl) {
            $cacheKey = $this->generateSecureKey($key);
            return $this->storeInCache($cacheKey, $value, $ttl);
        }, $context);
    }

    public function get(string $key, SecurityContext $context): mixed
    {
        return $this->security->executeCriticalOperation(function() use ($key) {
            $cacheKey = $this->generateSecureKey($key);
            return $this->getFromCache($cacheKey);
        }, $context);
    }

    public function forget(string $key, SecurityContext $context): bool
    {
        return $this->security->executeCriticalOperation(function() use ($key) {
            $cacheKey = $this->generateSecureKey($key);
            
            if (Cache::has($cacheKey)) {
                Cache::forget($cacheKey);
                $this->audit->logCacheInvalidation($key);
                return true;
            }
            
            return false;
        }, $context);
    }

    public function tags(array $tags): static
    {
        $validatedTags = array_map(
            fn($tag) => $this->validator->validateTag($tag),
            $tags
        );
        
        return Cache::tags($validatedTags);
    }

    private function generateSecureKey(string $key): string
    {
        $context = [
            'prefix' => config('cache.prefix'),
            'version' => config('cache.version'),
            'timestamp' => time()
        ];

        return hash_hmac(
            'sha256',
            $key . serialize($context),
            config('app.key')
        );
    }

    private function storeInCache(string $key, $value, ?int $ttl = null): bool
    {
        try {
            $encrypted = $this->prepareForCache($value);
            $ttl = $ttl ?? config('cache.ttl');
            
            $stored = Cache::put($key, $encrypted, $ttl);
            
            if ($stored) {
                $this->audit->logCacheStore($key);
            }
            
            return $stored;
        } catch (\Throwable $e) {
            $this->handleCacheFailure($e, 'store', $key);
            return false;
        }
    }

    private function getFromCache(string $key): mixed
    {
        try {
            $encrypted = Cache::get($key);
            
            if ($encrypted === null) {
                return null;
            }

            $this->validator->validateCacheData($encrypted);
            return $this->decryptFromCache($encrypted);
            
        } catch (\Throwable $e) {
            $this->handleCacheFailure($e, 'retrieve', $key);
            return null;
        }
    }

    private function prepareForCache($value): array
    {
        $serialized = serialize($value);
        $encrypted = $this->encryption->encrypt($serialized);
        
        return [
            'data' => $encrypted,
            'hash' => $this->calculateHash($serialized),
            'metadata' => $this->getMetadata()
        ];
    }

    private function decryptFromCache(array $encrypted): mixed
    {
        $decrypted = $this->encryption->decrypt($encrypted['data']);
        
        if (!hash_equals($encrypted['hash'], $this->calculateHash($decrypted))) {
            throw new SecurityException('Cache data integrity check failed');
        }

        return unserialize($decrypted);
    }

    private function calculateHash(string $data): string
    {
        return hash_hmac('sha256', $data, config('app.key'));
    }

    private function getMetadata(): array
    {
        return [
            'created_at' => time(),
            'version' => config('cache.version'),
            'encryption' => config('cache.encryption_version')
        ];
    }

    private function handleCacheFailure(\Throwable $e, string $operation, string $key): void
    {
        $this->audit->logCacheFailure($e, $operation, $key);
        
        if ($this->isSecurityRelated($e)) {
            $this->security->handleSecurityEvent($e);
        }
    }
}
