namespace App\Core\Cache;

use Illuminate\Support\Facades\{Cache, Log};
use Illuminate\Cache\TaggedCache;
use App\Core\Security\SecurityManager;
use App\Core\Monitoring\MonitoringService;
use App\Core\Exceptions\CacheException;

class CacheManager
{
    protected SecurityManager $security;
    protected MonitoringService $monitor;
    protected string $prefix = 'cms';
    protected array $tags = [];
    protected int $defaultTtl = 3600;

    public function __construct(
        SecurityManager $security,
        MonitoringService $monitor
    ) {
        $this->security = $security;
        $this->monitor = $monitor;
    }

    public function remember(string $key, callable $callback, ?int $ttl = null): mixed
    {
        $cacheKey = $this->getCacheKey($key);
        $ttl = $ttl ?? $this->defaultTtl;

        try {
            if ($this->tags) {
                return $this->getTaggedCache()->remember($cacheKey, $ttl, $callback);
            }

            return Cache::remember($cacheKey, $ttl, $callback);

        } catch (\Exception $e) {
            $this->handleCacheFailure($e, $cacheKey);
            return $callback();
        }
    }

    public function rememberForever(string $key, callable $callback): mixed
    {
        $cacheKey = $this->getCacheKey($key);

        try {
            if ($this->tags) {
                return $this->getTaggedCache()->rememberForever($cacheKey, $callback);
            }

            return Cache::rememberForever($cacheKey, $callback);

        } catch (\Exception $e) {
            $this->handleCacheFailure($e, $cacheKey);
            return $callback();
        }
    }

    public function put(string $key, mixed $value, ?int $ttl = null): bool
    {
        $cacheKey = $this->getCacheKey($key);
        $ttl = $ttl ?? $this->defaultTtl;

        try {
            if ($this->tags) {
                return $this->getTaggedCache()->put($cacheKey, $value, $ttl);
            }

            return Cache::put($cacheKey, $value, $ttl);

        } catch (\Exception $e) {
            $this->handleCacheFailure($e, $cacheKey);
            return false;
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $cacheKey = $this->getCacheKey($key);

        try {
            if ($this->tags) {
                return $this->getTaggedCache()->get($cacheKey, $default);
            }

            return Cache::get($cacheKey, $default);

        } catch (\Exception $e) {
            $this->handleCacheFailure($e, $cacheKey);
            return $default;
        }
    }

    public function forget(string $key): bool
    {
        $cacheKey = $this->getCacheKey($key);

        try {
            if ($this->tags) {
                return $this->getTaggedCache()->forget($cacheKey);
            }

            return Cache::forget($cacheKey);

        } catch (\Exception $e) {
            $this->handleCacheFailure($e, $cacheKey);
            return false;
        }
    }

    public function flush(): bool
    {
        try {
            if ($this->tags) {
                return $this->getTaggedCache()->flush();
            }

            $pattern = $this->getCacheKey('*');
            $keys = Cache::getRedis()->keys($pattern);

            foreach ($keys as $key) {
                Cache::forget($key);
            }

            return true;

        } catch (\Exception $e) {
            $this->handleCacheFailure($e, 'flush');
            return false;
        }
    }

    public function tags(array|string $tags): self
    {
        $this->tags = is_array($tags) ? $tags : [$tags];
        return $this;
    }

    public function prefix(string $prefix): self
    {
        $this->prefix = $prefix;
        return $this;
    }

    public function setDefaultTtl(int $ttl): self
    {
        $this->defaultTtl = $ttl;
        return $this;
    }

    protected function getCacheKey(string $key): string
    {
        return sprintf('%s:%s', $this->prefix, $key);
    }

    protected function getTaggedCache(): TaggedCache
    {
        return Cache::tags($this->tags);
    }

    protected function handleCacheFailure(\Exception $e, string $key): void
    {
        $this->monitor->recordCacheFailure($key, $e);

        Log::error('Cache operation failed', [
            'key' => $key,
            'tags' => $this->tags,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        if ($this->isCriticalFailure($e)) {
            throw new CacheException(
                'Critical cache failure: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    protected function isCriticalFailure(\Exception $e): bool
    {
        return $e->getCode() >= 500 || 
               str_contains($e->getMessage(), 'Redis') ||
               str_contains($e->getMessage(), 'connection');
    }
}
