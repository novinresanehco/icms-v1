<?php

namespace App\Core\Services;

use App\Core\Security\{EncryptionService, AuditService};
use Illuminate\Support\Facades\Cache;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Psr\SimpleCache\InvalidArgumentException;

class CacheService
{
    protected CacheRepository $cache;
    protected EncryptionService $encryption;
    protected AuditService $auditService;
    protected array $encryptedPrefixes = [
        'user:',
        'content:sensitive:',
        'auth:'
    ];

    protected int $defaultTtl = 3600;
    protected bool $encryptionEnabled = true;

    public function __construct(
        CacheRepository $cache,
        EncryptionService $encryption,
        AuditService $auditService
    ) {
        $this->cache = $cache;
        $this->encryption = $encryption;
        $this->auditService = $auditService;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        try {
            $value = $this->cache->get($key);

            if ($value === null) {
                return $default;
            }

            if ($this->shouldEncrypt($key)) {
                $value = $this->encryption->decrypt($value);
            }

            return $value;

        } catch (\Exception $e) {
            $this->handleError('cache_get_failed', $e, ['key' => $key]);
            return $default;
        }
    }

    public function put(string $key, mixed $value, int $ttl = null): bool
    {
        try {
            if ($this->shouldEncrypt($key)) {
                $value = $this->encryption->encrypt($value);
            }

            return $this->cache->put($key, $value, $ttl ?? $this->defaultTtl);

        } catch (\Exception $e) {
            $this->handleError('cache_put_failed', $e, ['key' => $key]);
            return false;
        }
    }

    public function forever(string $key, mixed $value): bool
    {
        try {
            if ($this->shouldEncrypt($key)) {
                $value = $this->encryption->encrypt($value);
            }

            return $this->cache->forever($key, $value);

        } catch (\Exception $e) {
            $this->handleError('cache_forever_failed', $e, ['key' => $key]);
            return false;
        }
    }

    public function remember(string $key, \Closure $callback, int $ttl = null): mixed
    {
        try {
            $value = $this->get($key);

            if ($value !== null) {
                return $value;
            }

            $value = $callback();
            $this->put($key, $value, $ttl);

            return $value;

        } catch (\Exception $e) {
            $this->handleError('cache_remember_failed', $e, ['key' => $key]);
            return $callback();
        }
    }

    public function forget(string $key): bool
    {
        try {
            return $this->cache->forget($key);

        } catch (\Exception $e) {
            $this->handleError('cache_forget_failed', $e, ['key' => $key]);
            return false;
        }
    }

    public function tags(array $tags): CacheRepository
    {
        return $this->cache->tags($tags);
    }

    public function flush(): bool
    {
        try {
            return $this->cache->flush();

        } catch (\Exception $e) {
            $this->handleError('cache_flush_failed', $e);
            return false;
        }
    }

    public function increment(string $key, int $value = 1): int|bool
    {
        try {
            return $this->cache->increment($key, $value);

        } catch (\Exception $e) {
            $this->handleError('cache_increment_failed', $e, ['key' => $key]);
            return false;
        }
    }

    public function decrement(string $key, int $value = 1): int|bool
    {
        try {
            return $this->cache->decrement($key, $value);

        } catch (\Exception $e) {
            $this->handleError('cache_decrement_failed', $e, ['key' => $key]);
            return false;
        }
    }

    protected function shouldEncrypt(string $key): bool
    {
        if (!$this->encryptionEnabled) {
            return false;
        }

        foreach ($this->encryptedPrefixes as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
    }

    protected function handleError(string $event, \Exception $e, array $context = []): void
    {
        $this->auditService->logSecurityEvent($event, [
            'error' => $e->getMessage(),
            'context' => $context
        ]);
    }
}
