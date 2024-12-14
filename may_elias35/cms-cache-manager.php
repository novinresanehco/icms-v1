<?php

namespace App\Core\Cache;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;
use Psr\SimpleCache\CacheInterface;
use App\Core\Exceptions\CacheException;
use Illuminate\Database\Eloquent\Model;
use Exception;

class CacheManager implements CacheInterface
{
    protected array $config = [
        'default_ttl' => 3600,
        'prefix' => 'cms_cache',
        'version' => '1.0',
        'lock_timeout' => 5,
        'lock_wait' => 1
    ];

    protected array $stores = [];
    protected array $tags = [];
    protected CacheMetrics $metrics;
    protected LockManager $lockManager;

    public function __construct(CacheMetrics $metrics, LockManager $lockManager)
    {
        $this->metrics = $metrics;
        $this->lockManager = $lockManager;
    }

    public function get($key, $default = null)
    {
        try {
            $cacheKey = $this->buildKey($key);
            $startTime = microtime(true);
            
            $value = Cache::tags($this->tags)->get($cacheKey);
            
            $this->recordMetrics('get', $key, microtime(true) - $startTime, !is_null($value));
            
            return $value ?? $default;
        } catch (Exception $e) {
            $this->handleException('get', $e);
            return $default;
        }
    }

    public function set($key, $value, $ttl = null)
    {
        try {
            $cacheKey = $this->buildKey($key);
            $startTime = microtime(true);
            
            $result = Cache::tags($this->tags)->put(
                $cacheKey,
                $value,
                $this->normalizeTtl($ttl)
            );
            
            $this->recordMetrics('set', $key, microtime(true) - $startTime, true);
            
            return $result;
        } catch (Exception $e) {
            $this->handleException('set', $e);
            return false;
        }
    }

    public function delete($key)
    {
        try {
            $cacheKey = $this->buildKey($key);
            return Cache::tags($this->tags)->forget($cacheKey);
        } catch (Exception $e) {
            $this->handleException('delete', $e);
            return false;
        }
    }

    public function clear()
    {
        try {
            if (!empty($this->tags)) {
                return Cache::tags($this->tags)->flush();
            }
            return Cache::flush();
        } catch (Exception $e) {
            $this->handleException('clear', $e);
            return false;
        }
    }

    public function getMultiple($keys, $default = null)
    {
        try {
            $result = [];
            foreach ($keys as $key) {
                $result[$key] = $this->get($key, $default);
            }
            return $result;
        } catch (Exception $e) {
            $this->handleException('getMultiple', $e);
            return array_fill_keys($keys, $default);
        }
    }

    public function setMultiple($values, $ttl = null)
    {
        try {
            foreach ($values as $key => $value) {
                $this->set($key, $value, $ttl);
            }
            return true;
        } catch (Exception $e) {
            $this->handleException('setMultiple', $e);
            return false;
        }
    }

    public function deleteMultiple($keys)
    {
        try {
            foreach ($keys as $key) {
                $this->delete($key);
            }
            return true;
        } catch (Exception $e) {
            $this->handleException('deleteMultiple', $e);
            return false;
        }
    }

    public function has($key)
    {
        try {
            $cacheKey = $this->buildKey($key);
            return Cache::tags($this->tags)->has($cacheKey);
        } catch (Exception $e) {
            $this->handleException('has', $e);
            return false;
        }
    }

    public function remember(string $key, $ttl, \Closure $callback)
    {
        try {
            $value = $this->get($key);
            
            if (!is_null($value)) {
                return $value;
            }
            
            $lock = $this->lockManager->lock($this->buildKey($key), $this->config['lock_timeout']);
            
            try {
                // Double-check after acquiring lock
                $value = $this->get($key);
                if (!is_null($value)) {
                    return $value;
                }
                
                $value = $callback();
                $this->set($key, $value, $ttl);
                
                return $value;
            } finally {
                $lock->release();
            }
        } catch (Exception $e) {
            $this->handleException('remember', $e);
            return $callback();
        }
    }

    public function tags(array $tags): self
    {
        $this->tags = array_merge($this->tags, $tags);
        return $this;
    }

    public function clearTags(array $tags): bool
    {
        try {
            return Cache::tags($tags)->flush();
        } catch (Exception $e) {
            $this->handleException('clearTags', $e);
            return false;
        }
    }

    public function rememberModel(Model $model, string $key, $ttl = null): ?Model
    {
        $modelTag = $this->getModelTag($model);
        return $this->tags([$modelTag])->remember($key, $ttl, function() use ($model) {
            return $model;
        });
    }

    public function invalidateModel(Model $model): bool
    {
        return $this->clearTags([$this->getModelTag($model)]);
    }

    protected function getModelTag(Model $model): string
    {
        return sprintf(
            '%s_%s',
            strtolower(class_basename($model)),
            $model->getKey()
        );
    }

    protected function buildKey(string $key): string
    {
        return sprintf(
            '%s:%s:%s',
            $this->config['prefix'],
            $this->config['version'],
            $key
        );
    }

    protected function normalizeTtl($ttl): int
    {
        if (is_null($ttl)) {
            return $this->config['default_ttl'];
        }
        
        if ($ttl instanceof \DateInterval) {
            return (new \DateTime())->add($ttl)->getTimestamp() - time();
        }
        
        return (int) $ttl;
    }

    protected function recordMetrics(string $operation, string $key, float $duration, bool $success): void
    {
        $this->metrics->record([
            'operation' => $operation,
            'key' => $key,
            'duration' => $duration,
            'success' => $success,
            'tags' => $this->tags
        ]);
    }

    protected function handleException(string $operation, Exception $e): void
    {
        Log::error("Cache {$operation} operation failed", [
            'exception' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        
        throw new CacheException(
            "Cache operation '{$operation}' failed: {$e->getMessage()}",
            0,
            $e
        );
    }
}

class CacheMetrics
{
    protected array $metrics = [];
    
    public function record(array $data): void
    {
        $this->metrics[] = array_merge($data, [
            'timestamp' => microtime(true)
        ]);
        
        if (count($this->metrics) > 1000) {
            $this->flush();
        }
    }
    
    public function getMetrics(): array
    {
        return $this->metrics;
    }
    
    public function flush(): void
    {
        // Implement metric storage/aggregation logic here
        $this->metrics = [];
    }
}

class LockManager
{
    protected array $locks = [];
    
    public function lock(string $key, int $timeout): Lock
    {
        $lock = new Lock($key, $timeout);
        $this->locks[$key] = $lock;
        return $lock;
    }
    
    public function release(string $key): void
    {
        if (isset($this->locks[$key])) {
            $this->locks[$key]->release();
            unset($this->locks[$key]);
        }
    }
}

class Lock
{
    protected string $key;
    protected int $timeout;
    protected bool $acquired = false;
    
    public function __construct(string $key, int $timeout)
    {
        $this->key = $key;
        $this->timeout = $timeout;
        $this->acquire();
    }
    
    protected function acquire(): void
    {
        $this->acquired = Cache::lock($this->key, $this->timeout)->get();
        
        if (!$this->acquired) {
            throw new CacheException("Failed to acquire lock for key: {$this->key}");
        }
    }
    
    public function release(): void
    {
        if ($this->acquired) {
            Cache::lock($this->key)->release();
            $this->acquired = false;
        }
    }
    
    public function __destruct()
    {
        $this->release();
    }
}

class CacheException extends \Exception
{
    // Custom exception handling logic can be added here
}
