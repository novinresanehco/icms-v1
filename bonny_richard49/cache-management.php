<?php

namespace App\Core\Cache;

use App\Core\Security\CoreSecurityManager;
use App\Core\Monitoring\MetricsCollector;
use App\Core\Security\SecurityContext;
use Illuminate\Support\Facades\Cache;
use Psr\Log\LoggerInterface;

class CacheManager implements CacheManagerInterface 
{
    private CoreSecurityManager $security;
    private MetricsCollector $metrics;
    private LoggerInterface $logger;
    private array $config;

    private const CACHE_VERSION = 'v1';
    private const MAX_KEY_LENGTH = 250;
    private const DEFAULT_TTL = 3600;

    public function __construct(
        CoreSecurityManager $security,
        MetricsCollector $metrics,
        LoggerInterface $logger,
        array $config
    ) {
        $this->security = $security;
        $this->metrics = $metrics;
        $this->logger = $logger;
        $this->config = $config;
    }

    public function get(string $key, ?SecurityContext $context = null): mixed 
    {
        $operation = new CacheOperation('get', [
            'key' => $this->validateAndFormatKey($key)
        ]);

        try {
            $startTime = microtime(true);
            $value = Cache::get($this->buildKey($key));
            
            $this->metrics->recordCacheAccess('get', $key, microtime(true) - $startTime);
            
            if ($value !== null) {
                $this->validateCachedData($value, $context);
            }
            
            return $value;
            
        } catch (\Exception $e) {
            $this->handleCacheFailure('get', $key, $e);
            throw $e;
        }
    }

    public function set(string $key, mixed $value, ?int $ttl = null, ?SecurityContext $context = null): bool 
    {
        $operation = new CacheOperation('set', [
            'key' => $this->validateAndFormatKey($key),
            'ttl' => $ttl ?? self::DEFAULT_TTL
        ]);

        try {
            if ($context) {
                $this->security->validateAccess($context);
            }

            $startTime = microtime(true);
            $success = Cache::put(
                $this->buildKey($key),
                $this->prepareValueForCache($value),
                $ttl ?? self::DEFAULT_TTL
            );
            
            $this->metrics->recordCacheAccess('set', $key, microtime(true) - $startTime);
            
            return $success;
            
        } catch (\Exception $e) {
            $this->handleCacheFailure('set', $key, $e);
            throw $e;
        }
    }

    public function delete(string $key, ?SecurityContext $context = null): bool 
    {
        $operation = new CacheOperation('delete', [
            'key' => $this->validateAndFormatKey($key)
        ]);

        try {
            if ($context) {
                $this->security->validateAccess($context);
            }

            $startTime = microtime(true);
            $success = Cache::forget($this->buildKey($key));
            
            $this->metrics->recordCacheAccess('delete', $key, microtime(true) - $startTime);
            
            return $success;
            
        } catch (\Exception $e) {
            $this->handleCacheFailure('delete', $key, $e);
            throw $e;
        }
    }

    public function flush(?SecurityContext $context = null): bool 
    {
        $operation = new CacheOperation('flush', []);

        try {
            if ($context) {
                $this->security->validateAccess($context);
            }

            $startTime = microtime(true);
            $success = Cache::flush();
            
            $this->metrics->recordCacheAccess('flush', 'all', microtime(true) - $startTime);
            
            return $success;
            
        } catch (\Exception $e) {
            $this->handleCacheFailure('flush', 'all', $e);
            throw $e;
        }
    }

    public function remember(string $key, callable $callback, ?int $ttl = null, ?SecurityContext $context = null): mixed 
    {
        $operation = new CacheOperation('remember', [
            'key' => $this->validateAndFormatKey($key),
            'ttl' => $ttl ?? self::DEFAULT_TTL
        ]);

        try {
            if ($context) {
                $this->security->validateAccess($context);
            }

            $startTime = microtime(true);
            $value = Cache::remember(
                $this->buildKey($key),
                $ttl ?? self::DEFAULT_TTL,
                function() use ($callback) {
                    return $this->prepareValueForCache($callback());
                }
            );
            
            $this->metrics->recordCacheAccess('remember', $key, microtime(true) - $startTime);
            
            return $value;
            
        } catch (\Exception $e) {
            $this->handleCacheFailure('remember', $key, $e);
            throw $e;
        }
    }

    public function tags(array $tags): self 
    {
        foreach ($tags as $tag) {
            $this->validateTag($tag);
        }
        
        Cache::tags($tags);
        return $this;
    }

    protected function validateAndFormatKey(string $key): string 
    {
        if (empty($key)) {
            throw new CacheException('Cache key cannot be empty');
        }

        if (strlen($key) > self::MAX_KEY_LENGTH) {
            throw new CacheException('Cache key exceeds maximum length');
        }

        return preg_replace('/[^a-zA-Z0-9._-]/', '', $key);
    }

    protected function validateTag(string $tag): void 
    {
        if (empty($tag)) {
            throw new CacheException('Cache tag cannot be empty');
        }

        if (!preg_match('/^[a-zA-Z0-9._-]+$/', $tag)) {
            throw new CacheException('Invalid cache tag format');
        }
    }

    protected function buildKey(string $key): string 
    {
        return sprintf('%s:%s:%s', 
            $this->config['prefix'] ?? 'app',
            self::CACHE_VERSION,
            $key
        );
    }

    protected function prepareValueForCache(mixed $value): mixed 
    {
        if (is_object($value) && !$value instanceof \Serializable) {
            throw new CacheException('Cannot cache non-serializable object');
        }
        
        return $value;
    }

    protected function validateCachedData(mixed $value, ?SecurityContext $context): void 
    {
        if ($context && is_object($value)) {
            $this->security->validateDataAccess($value, $context);
        }
    }

    protected function handleCacheFailure(string $operation, string $key, \Exception $e): void 
    {
        $this->logger->error('Cache operation failed', [
            'operation' => $operation,
            'key' => $key,
            'exception' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        $this->metrics->incrementFailureCount("cache.{$operation}");

        // Execute fallback strategy if configured
        if ($this->shouldExecuteFallback($operation)) {
            $this->executeFallbackStrategy($operation, $key);
        }
    }

    protected function shouldExecuteFallback(string $operation): bool 
    {
        return isset($this->config['fallback_strategies'][$operation]);
    }

    protected function executeFallbackStrategy(string $operation, string $key): void 
    {
        $strategy = $this->config['fallback_strategies'][$operation];
        $strategy->execute($key);
    }
}

class CacheOperation
{
    private string $type;
    private array $params;

    public function __construct(string $type, array $params) 
    {
        $this->type = $type;
        $this->params = $params;
    }

    public function getType(): string 
    {
        return $this->type;
    }

    public function getParams(): array 
    {
        return $this->params;
    }
}

class CacheException extends \Exception {}
