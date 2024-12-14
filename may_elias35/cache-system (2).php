<?php

namespace App\Core\Cache;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CacheManager
{
    protected array $stores = [];
    protected array $tags = [];
    protected ?string $prefix = null;
    protected ?int $ttl = null;

    public function store(string $name = null): self
    {
        $this->currentStore = $name;
        return $this;
    }

    public function tags(array $tags): self
    {
        $this->tags = $tags;
        return $this;
    }

    public function prefix(string $prefix): self
    {
        $this->prefix = $prefix;
        return $this;
    }

    public function ttl(int $seconds): self
    {
        $this->ttl = $seconds;
        return $this;
    }

    public function remember(string $key, \Closure $callback)
    {
        $cacheKey = $this->buildKey($key);
        $ttl = $this->ttl ?? config('cache.ttl', 3600);

        try {
            return Cache::tags($this->tags)->remember($cacheKey, $ttl, $callback);
        } catch (\Exception $e) {
            Log::error('Cache operation failed', [
                'key' => $cacheKey,
                'error' => $e->getMessage()
            ]);
            return $callback();
        }
    }

    public function put(string $key, $value, ?int $ttl = null): bool
    {
        $cacheKey = $this->buildKey($key);
        $ttl = $ttl ?? $this->ttl ?? config('cache.ttl', 3600);

        try {
            return Cache::tags($this->tags)->put($cacheKey, $value, $ttl);
        } catch (\Exception $e) {
            Log::error('Cache put failed', [
                'key' => $cacheKey,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    public function get(string $key, $default = null)
    {
        $cacheKey = $this->buildKey($key);

        try {
            return Cache::tags($this->tags)->get($cacheKey, $default);
        } catch (\Exception $e) {
            Log::error('Cache get failed', [
                'key' => $cacheKey,
                'error' => $e->getMessage()
            ]);
            return $default;
        }
    }

    public function forget(string $key): bool
    {
        $cacheKey = $this->buildKey($key);

        try {
            return Cache::tags($this->tags)->forget($cacheKey);
        } catch (\Exception $e) {
            Log::error('Cache forget failed', [
                'key' => $cacheKey,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    public function flush(): bool
    {
        try {
            if (!empty($this->tags)) {
                return Cache::tags($this->tags)->flush();
            }
            return Cache::flush();
        } catch (\Exception $e) {
            Log::error('Cache flush failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    protected function buildKey(string $key): string
    {
        if ($this->prefix) {
            $key = "{$this->prefix}:{$key}";
        }
        return $key;
    }
}

namespace App\Core\Cache;

class CacheKey
{
    private string $prefix;
    private array $segments = [];

    public function __construct(string $prefix)
    {
        $this->prefix = $prefix;
    }

    public function add(string $segment): self
    {
        $this->segments[] = $segment;
        return $this;
    }

    public function build(): string
    {
        $segments = array_filter($this->segments);
        return implode(':', array_merge([$this->prefix], $segments));
    }
}

namespace App\Core\Cache;

class DistributedLock
{
    private string $name;
    private int $timeout;
    private $owner;

    public function __construct(string $name, int $timeout = 30)
    {
        $this->name = $name;
        $this->timeout = $timeout;
        $this->owner = uniqid();
    }

    public function acquire(): bool
    {
        return Cache::add($this->getLockKey(), $this->owner, $this->timeout);
    }

    public function release(): bool
    {
        if ($this->isOwnedByCurrentProcess()) {
            return Cache::forget($this->getLockKey());
        }
        return false;
    }

    protected function isOwnedByCurrentProcess(): bool
    {
        return Cache::get($this->getLockKey()) === $this->owner;
    }

    protected function getLockKey(): string
    {
        return "lock:{$this->name}";
    }
}

namespace App\Core\Cache\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class InvalidateCacheJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    private array $tags;
    private ?string $key;

    public function __construct(array $tags, ?string $key = null)
    {
        $this->tags = $tags;
        $this->key = $key;
    }

    public function handle(): void
    {
        if ($this->key) {
            Cache::tags($this->tags)->forget($this->key);
        } else {
            Cache::tags($this->tags)->flush();
        }
    }
}

namespace App\Core\Cache\Events;

class CacheInvalidated
{
    public array $tags;
    public ?string $key;
    public string $reason;

    public function __construct(array $tags, ?string $key, string $reason)
    {
        $this->tags = $tags;
        $this->key = $key;
        $this->reason = $reason;
    }
}

namespace App\Core\Cache\Middleware;

use Closure;
use Illuminate\Http\Request;

class CacheResponse
{
    public function handle(Request $request, Closure $next)
    {
        if (!$request->isMethod('GET')) {
            return $next($request);
        }

        $key = 'response:' . sha1($request->fullUrl());
        
        if ($cachedResponse = Cache::get($key)) {
            return response($cachedResponse['content'])
                ->header('X-Cache', 'HIT')
                ->setStatusCode($cachedResponse['status']);
        }

        $response = $next($request);

        if ($response->status() === 200) {
            Cache::put($key, [
                'content' => $response->getContent(),
                'status' => $response->status()
            ], now()->addMinutes(config('cache.ttl', 60)));
        }

        return $response->header('X-Cache', 'MISS');
    }
}

namespace App\Core\Cache\Console;

use Illuminate\Console\Command;

class ClearCacheCommand extends Command
{
    protected $signature = 'cache:clear {tags?*} {--key=}';
    protected $description = 'Clear cache with optional tags and key';

    public function handle()
    {
        $tags = $this->argument('tags');
        $key = $this->option('key');

        if (!empty($tags)) {
            if ($key) {
                Cache::tags($tags)->forget($key);
                $this->info("Cleared cache for key [{$key}] with tags: " . implode(', ', $tags));
            } else {
                Cache::tags($tags)->flush();
                $this->info("Cleared all cache with tags: " . implode(', ', $tags));
            }
        } else {
            Cache::flush();
            $this->info("Cleared all cache");
        }
    }
}

namespace App\Core\Cache\Providers;

use Illuminate\Support\ServiceProvider;

class CacheServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CacheManager::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                ClearCacheCommand::class,
            ]);
        }
    }
}
