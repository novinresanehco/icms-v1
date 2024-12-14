<?php

namespace App\Core\Cache;

use App\Core\Security\SecurityContext;
use App\Core\Monitoring\SystemMonitor;
use Illuminate\Support\Facades\Redis;
use Psr\Log\LoggerInterface;

class CacheManager implements CacheInterface
{
    private SecurityContext $security;
    private SystemMonitor $monitor;
    private LoggerInterface $logger;
    private array $config;

    private const MAX_RETRY_ATTEMPTS = 3;
    private const RETRY_DELAY_MS = 100;

    public function __construct(
        SecurityContext $security,
        SystemMonitor $monitor,
        LoggerInterface $logger,
        array $config
    ) {
        $this->security = $security;
        $this->monitor = $monitor;
        $this->logger = $logger;
        $this->config = $config;
    }

    public function get(string $key, bool $useSecureGet = true): mixed
    {
        $monitoringId = $this->monitor->startOperation('cache_get');

        try {
            $this->validateKey($key);
            $secureKey = $this->getSecureKey($key);

            $value = $useSecureGet 
                ? $this->secureGet($secureKey)
                : Redis::get($secureKey);

            $this->monitor->recordMetric($monitoringId, 'cache_hit', !is_null($value));
            
            return $value ? $this->deserialize($value) : null;

        } catch (\Exception $e) {
            $this->handleCacheFailure('get', $key, $e);
            throw new CacheException('Cache get failed', 0, $e);
        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    public function set(string $key, mixed $value, int $ttl = null): bool
    {
        $monitoringId = $this->monitor->startOperation('cache_set');

        try {
            $this->validateKey($key);
            $this->validateValue($value);

            $secureKey = $this->getSecureKey($key);
            $serializedValue = $this->serialize($value);
            
            return $this->secureSet($secureKey, $serializedValue, $ttl ?? $this->config['default_ttl']);

        } catch (\Exception $e) {
            $this->handleCacheFailure('set', $key, $e);
            throw new CacheException('Cache set failed', 0, $e);
        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    public function delete(string $key): bool
    {
        $monitoringId = $this->monitor->startOperation('cache_delete');

        try {
            $this->validateKey($key);
            $secureKey = $this->getSecureKey($key);
            
            return Redis::del($secureKey) > 0;

        } catch (\Exception $e) {
            $this->handleCacheFailure('delete', $key, $e);
            throw new CacheException('Cache delete failed', 0, $e);
        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    private function secureGet(string $key): ?string
    {
        $attempts = 0;
        $lastException = null;

        while ($attempts < self::MAX_RETRY_ATTEMPTS) {
            try {
                return Redis::get($key);
            } catch (\Exception $e) {
                $lastException = $e;
                $attempts++;
                if ($attempts >= self::MAX_RETRY_ATTEMPTS) {
                    break;
                }
                usleep(self::RETRY_DELAY_MS * $attempts);
            }
        }

        throw new CacheException(
            'Secure get failed after retries',
            0,
            $lastException
        );
    }

    private function secureSet(string $key, string $value, int $ttl): bool
    {
        $attempts = 0;
        $lastException = null;

        while ($attempts < self::MAX_RETRY_ATTEMPTS) {
            try {
                return Redis::setex($key, $ttl, $value);
            } catch (\Exception $e) {
                $lastException = $e;
                $attempts++;
                if ($attempts >= self::MAX_RETRY_ATTEMPTS) {
                    break;
                }
                usleep(self::RETRY_DELAY_MS * $attempts);
            }
        }

        throw new CacheException(
            'Secure set failed after retries',
            0,
            $lastException
        );
    }

    private function validateKey(string $key): void
    {
        if (empty($key) || strlen($key) > 1024) {
            throw new CacheValidationException('Invalid cache key');
        }
    }

    private function validateValue(mixed $value): void
    {
        if (is_resource($value)) {
            throw new CacheValidationException('Cannot cache resource type');
        }
    }

    private function getSecureKey(string $key): string
    {
        return hash_hmac('sha256', $key, $this->config['key_salt']);
    }

    private function serialize(mixed $value): string
    {
        return serialize($value);
    }

    private function deserialize(string $value): mixed
    {
        return unserialize($value);
    }

    private function handleCacheFailure(string $operation, string $key, \Exception $e): void
    {
        $this->logger->error('Cache operation failed', [
            'operation' => $operation,
            'key' => $key,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}
