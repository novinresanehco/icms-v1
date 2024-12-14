namespace App\Core\Performance;

use Illuminate\Support\Facades\Cache;
use Psr\SimpleCache\CacheInterface;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;

class CacheManager implements CacheInterface
{
    private MetricsCollector $metrics;
    private array $config;
    private Redis $redis;

    public function __construct(MetricsCollector $metrics, array $config)
    {
        $this->metrics = $metrics;
        $this->config = $config;
        $this->redis = Redis::connection();
    }

    public function get($key, $default = null)
    {
        $startTime = microtime(true);
        
        try {
            $value = $this->redis->get($this->generateKey($key));
            
            if ($value === null) {
                $this->metrics->incrementCacheMiss($key);
                return $default;
            }
            
            $this->metrics->incrementCacheHit($key);
            $this->metrics->recordCacheAccessTime(microtime(true) - $startTime);
            
            return unserialize($value);
        } catch (\Throwable $e) {
            Log::error('Cache retrieval failed', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            
            $this->metrics->incrementCacheError($key);
            return $default;
        }
    }

    public function set($key, $value, $ttl = null)
    {
        $startTime = microtime(true);
        
        try {
            $serialized = serialize($value);
            $cacheKey = $this->generateKey($key);
            
            $ttl = $ttl ?? $this->config['default_ttl'] ?? 3600;
            
            $success = $this->redis->setex(
                $cacheKey,
                $ttl,
                $serialized
            );
            
            $this->metrics->recordCacheWriteTime(microtime(true) - $startTime);
            
            if (!$success) {
                throw new CacheException("Failed to set cache key: {$key}");
            }
            
            return true;
        } catch (\Throwable $e) {
            Log::error('Cache write failed', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            
            $this->metrics->incrementCacheError($key);
            return false;
        }
    }

    public function delete($key)
    {
        try {
            return $this->redis->del($this->generateKey($key)) > 0;
        } catch (\Throwable $e) {
            Log::error('Cache deletion failed', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    public function clear()
    {
        try {
            return $this->redis->flushdb();
        } catch (\Throwable $e) {
            Log::error('Cache clear failed', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    public function getMultiple($keys, $default = null)
    {
        $startTime = microtime(true);
        
        try {
            $mappedKeys = array_map([$this, 'generateKey'], (array) $keys);
            $values = $this->redis->mget($mappedKeys);
            
            $result = [];
            foreach ($keys as $i => $key) {
                $value = $values[$i] ? unserialize($values[$i]) : $default;
                $result[$key] = $value;
                
                $value === $default
                    ? $this->metrics->incrementCacheMiss($key)
                    : $this->metrics->incrementCacheHit($key);
            }
            
            $this->metrics->recordCacheAccessTime(microtime(true) - $startTime);
            
            return $result;
        } catch (\Throwable $e) {
            Log::error('Multiple cache retrieval failed', [
                'keys' => $keys,
                'error' => $e->getMessage()
            ]);
            
            return array_fill_keys($keys, $default);
        }
    }

    public function setMultiple($values, $ttl = null)
    {
        $startTime = microtime(true);
        
        try {
            $ttl = $ttl ?? $this->config['default_ttl'] ?? 3600;
            
            foreach ($values as $key => $value) {
                $this->set($key, $value, $ttl);
            }
            
            $this->metrics->recordCacheWriteTime(microtime(true) - $startTime);
            
            return true;
        } catch (\Throwable $e) {
            Log::error('Multiple cache write failed', [
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }

    public function deleteMultiple($keys)
    {
        try {
            $mappedKeys = array_map([$this, 'generateKey'], (array) $keys);
            return $this->redis->del($mappedKeys) > 0;
        } catch (\Throwable $e) {
            Log::error('Multiple cache deletion failed', [
                'keys' => $keys,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    public function has($key)
    {
        try {
            return $this->redis->exists($this->generateKey($key)) > 0;
        } catch (\Throwable $e) {
            Log::error('Cache check failed', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    private function generateKey(string $key): string
    {
        return sprintf(
            '%s:%s:%s',
            $this->config['prefix'] ?? 'cms',
            $this->config['version'] ?? 'v1',
            $key
        );
    }
}

class MetricsCollector 
{
    private const METRICS_PREFIX = 'metrics:cache:';
    private Redis $redis;

    public function __construct()
    {
        $this->redis = Redis::connection();
    }

    public function incrementCacheHit(string $key): void
    {
        $this->redis->incr(self::METRICS_PREFIX . 'hits');
        $this->redis->incr(self::METRICS_PREFIX . "key:{$key}:hits");
    }

    public function incrementCacheMiss(string $key): void
    {
        $this->redis->incr(self::METRICS_PREFIX . 'misses');
        $this->redis->incr(self::METRICS_PREFIX . "key:{$key}:misses");
    }

    public function incrementCacheError(string $key): void
    {
        $this->redis->incr(self::METRICS_PREFIX . 'errors');
        $this->redis->incr(self::METRICS_PREFIX . "key:{$key}:errors");
    }

    public function recordCacheAccessTime(float $time): void
    {
        $this->redis->lpush(self::METRICS_PREFIX . 'access_times', $time);
        $this->redis->ltrim(self::METRICS_PREFIX . 'access_times', 0, 999);
    }

    public function recordCacheWriteTime(float $time): void
    {
        $this->redis->lpush(self::METRICS_PREFIX . 'write_times', $time);
        $this->redis->ltrim(self::METRICS_PREFIX . 'write_times', 0, 999);
    }

    public function getMetrics(): array
    {
        return [
            'hits' => (int) $this->redis->get(self::METRICS_PREFIX . 'hits'),
            'misses' => (int) $this->redis->get(self::METRICS_PREFIX . 'misses'),
            'errors' => (int) $this->redis->get(self::METRICS_PREFIX . 'errors'),
            'access_times' => $this->redis->lrange(self::METRICS_PREFIX . 'access_times', 0, -1),
            'write_times' => $this->redis->lrange(self::METRICS_PREFIX . 'write_times', 0, -1)
        ];
    }
}
