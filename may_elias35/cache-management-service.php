namespace App\Core\Cache;

use App\Core\Security\SecurityManager;
use App\Core\Services\MetricsCollector;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Psr\Cache\CacheItemPoolInterface;

class CacheManager implements CacheItemPoolInterface
{
    private SecurityManager $security;
    private MetricsCollector $metrics;
    private array $config;
    private array $locks = [];

    public function __construct(
        SecurityManager $security,
        MetricsCollector $metrics,
        array $config
    ) {
        $this->security = $security;
        $this->metrics = $metrics;
        $this->config = $config;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $startTime = microtime(true);
        
        try {
            $value = Cache::get($this->prepareKey($key));
            
            $this->metrics->recordCacheOperation('get', microtime(true) - $startTime);
            $this->metrics->incrementCacheHits($value !== null);
            
            return $value ?? $default;
        } catch (\Throwable $e) {
            $this->handleCacheError('get', $key, $e);
            return $default;
        }
    }

    public function getMultiple(array $keys): array
    {
        $startTime = microtime(true);
        $preparedKeys = array_map([$this, 'prepareKey'], $keys);
        
        try {
            $values = Cache::many($preparedKeys);
            
            $this->metrics->recordCacheOperation(
                'getMultiple', 
                microtime(true) - $startTime
            );
            
            return array_combine($keys, $values);
        } catch (\Throwable $e) {
            $this->handleCacheError('getMultiple', implode(',', $keys), $e);
            return array_combine($keys, array_fill(0, count($keys), null));
        }
    }

    public function put(string $key, mixed $value, int $ttl = null): bool
    {
        $startTime = microtime(true);
        $preparedKey = $this->prepareKey($key);
        
        try {
            if ($this->shouldCache($value)) {
                Cache::put(
                    $preparedKey,
                    $this->prepareValue($value),
                    $this->normalizeTtl($ttl)
                );
                
                $this->metrics->recordCacheOperation(
                    'put',
                    microtime(true) - $startTime
                );
                return true;
            }
            return false;
        } catch (\Throwable $e) {
            $this->handleCacheError('put', $key, $e);
            return false;
        }
    }

    public function putMultiple(array $values, int $ttl = null): bool
    {
        $startTime = microtime(true);
        
        try {
            $prepared = [];
            foreach ($values as $key => $value) {
                if ($this->shouldCache($value)) {
                    $prepared[$this->prepareKey($key)] = $this->prepareValue($value);
                }
            }
            
            if (!empty($prepared)) {
                Cache::putMany($prepared, $this->normalizeTtl($ttl));
                
                $this->metrics->recordCacheOperation(
                    'putMultiple',
                    microtime(true) - $startTime
                );
                return true;
            }
            return false;
        } catch (\Throwable $e) {
            $this->handleCacheError('putMultiple', implode(',', array_keys($values)), $e);
            return false;
        }
    }

    public function remember(string $key, int $ttl, callable $callback): mixed
    {
        $startTime = microtime(true);
        $preparedKey = $this->prepareKey($key);
        
        try {
            $value = Cache::remember(
                $preparedKey,
                $this->normalizeTtl($ttl),
                function() use ($callback, $key) {
                    $value = $callback();
                    return $this->shouldCache($value) ? $this->prepareValue($value) : null;
                }
            );
            
            $this->metrics->recordCacheOperation(
                'remember',
                microtime(true) - $startTime
            );
            
            return $value;
        } catch (\Throwable $e) {
            $this->handleCacheError('remember', $key, $e);
            return $callback();
        }
    }

    public function forget(string $key): bool
    {
        $startTime = microtime(true);
        
        try {
            $result = Cache::forget($this->prepareKey($key));
            
            $this->metrics->recordCacheOperation(
                'forget',
                microtime(true) - $startTime
            );
            
            return $result;
        } catch (\Throwable $e) {
            $this->handleCacheError('forget', $key, $e);
            return false;
        }
    }

    public function flush(): bool
    {
        $startTime = microtime(true);
        
        try {
            $result = Cache::flush();
            
            $this->metrics->recordCacheOperation(
                'flush',
                microtime(true) - $startTime
            );
            
            Log::warning('Cache flushed', [
                'timestamp' => now()->toIso8601String()
            ]);
            
            return $result;
        } catch (\Throwable $e) {
            $this->handleCacheError('flush', 'all', $e);
            return false;
        }
    }

    protected function prepareKey(string $key): string
    {
        return hash('sha256', $this->config['prefix'] . ':' . $key);
    }

    protected function prepareValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map([$this, 'prepareValue'], $value);
        }
        
        if (is_object($value) && method_exists($value, 'getCacheableData')) {
            return $value->getCacheableData();
        }
        
        return $value;
    }

    protected function shouldCache(mixed $value): bool
    {
        return !is_null($value) && 
               !($value instanceof \Closure) &&
               $this->isValidSize($value);
    }

    protected function isValidSize(mixed $value): bool
    {
        $size = strlen(serialize($value));
        return $size <= $this->config['max_item_size'];
    }

    protected function normalizeTtl(?int $ttl): int
    {
        if (is_null($ttl)) {
            return $this->config['default_ttl'];
        }
        
        return min($ttl, $this->config['max_ttl']);
    }

    protected function handleCacheError(string $operation, string $key, \Throwable $e): void
    {
        Log::error('Cache operation failed', [
            'operation' => $operation,
            'key' => $key,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        
        $this->metrics->incrementCacheErrors($operation);
    }
}
