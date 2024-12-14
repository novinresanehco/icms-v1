<?php

namespace App\Core\Media\Cache;

use App\Core\Media\Models\Media;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use App\Core\Media\Config\CacheConfig;

class MediaCacheManager
{
    protected CacheConfig $config;
    protected CacheKeyGenerator $keyGenerator;
    protected CacheStrategyResolver $strategyResolver;

    public function __construct(
        CacheConfig $config,
        CacheKeyGenerator $keyGenerator,
        CacheStrategyResolver $strategyResolver
    ) {
        $this->config = $config;
        $this->keyGenerator = $keyGenerator;
        $this->strategyResolver = $strategyResolver;
    }

    public function get(Media $media, array $options = []): ?string
    {
        $key = $this->keyGenerator->generate($media, $options);
        $strategy = $this->strategyResolver->resolve($media, $options);

        return Cache::tags(['media', "media_{$media->id}"])
            ->remember($key, $strategy->getTTL(), function () use ($media, $options, $strategy) {
                return $this->processAndCache($media, $options, $strategy);
            });
    }

    protected function processAndCache(Media $media, array $options, CacheStrategy $strategy): string
    {
        $processor = $strategy->getProcessor();
        $path = $processor->process($media, $options);

        if ($strategy->shouldPersist()) {
            return $this->persistToStorage($path);
        }

        return $path;
    }

    public function invalidate(Media $media): void
    {
        Cache::tags(["media_{$media->id}"])->flush();
    }

    public function warmUp(Media $media): void
    {
        foreach ($this->config->preWarmConfigs as $config) {
            $this->get($media, $config);
        }
    }

    protected function persistToStorage(string $path): string
    {
        $storagePath = 'cache/' . md5($path) . '_' . basename($path);
        Storage::put($storagePath, file_get_contents($path));
        return $storagePath;
    }
}

class CacheKeyGenerator
{
    public function generate(Media $media, array $options): string
    {
        $elements = [
            'media_' . $media->id,
            'version_' . $media->updated_at->timestamp
        ];

        foreach ($options as $key => $value) {
            $elements[] = $key . '_' . $this->normalizeValue($value);
        }

        return implode(':', $elements);
    }

    protected function normalizeValue($value): string
    {
        if (is_array($value)) {
            return md5(serialize($value));
        }
        return (string) $value;
    }
}

abstract class CacheStrategy
{
    abstract public function getTTL(): int;
    abstract public function shouldPersist(): bool;
    abstract public function getProcessor(): MediaProcessorInterface;
}

class ImageCacheStrategy extends CacheStrategy
{
    protected CacheConfig $config;
    protected ImageProcessor $processor;

    public function __construct(CacheConfig $config, ImageProcessor $processor)
    {
        $this->config = $config;
        $this->processor = $processor;
    }

    public function getTTL(): int
    {
        return $this->config->imageCacheTTL;
    }

    public function shouldPersist(): bool
    {
        return true;
    }

    public function getProcessor(): MediaProcessorInterface
    {
        return $this->processor;
    }
}

class CacheStrategyResolver
{
    protected array $strategies = [];

    public function addStrategy(string $type, CacheStrategy $strategy): void
    {
        $this->strategies[$type] = $strategy;
    }

    public function resolve(Media $media, array $options): CacheStrategy
    {
        $type = $this->determineType($media);
        
        if (!isset($this->strategies[$type])) {
            throw new UnsupportedMediaTypeException("No cache strategy for type: {$type}");
        }

        return $this->strategies[$type];
    }

    protected function determineType(Media $media): string
    {
        if (str_starts_with($media->mime_type, 'image/')) {
            return 'image';
        }
        if (str_starts_with($media->mime_type, 'video/')) {
            return 'video';
        }
        if (str_starts_with($media->mime_type, 'application/pdf')) {
            return 'document';
        }
        
        return 'default';
    }
}

namespace App\Core\Media\Cache\Middleware;

class CacheControlMiddleware
{
    protected CacheConfig $config;

    public function __construct(CacheConfig $config)
    {
        $this->config = $config;
    }

    public function handle($request, \Closure $next)
    {
        $response = $next($request);

        if ($this->shouldCache($request)) {
            $this->addCacheHeaders($response);
        } else {
            $this->addNoCacheHeaders($response);
        }

        return $response;
    }

    protected function shouldCache($request): bool
    {
        return !$request->has('nocache') && 
               $request->method() === 'GET' &&
               $this->isMediaRoute($request);
    }

    protected function addCacheHeaders($response): void
    {
        $response->header('Cache-Control', 'public, max-age=' . $this->config->browserCacheTTL);
        $response->header('Expires', now()->addSeconds($this->config->browserCacheTTL)->toRfc7231String());
    }

    protected function addNoCacheHeaders($response): void
    {
        $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, post-check=0, pre-check=0');
        $response->header('Pragma', 'no-cache');
        $response->header('Expires', 'Sat, 26 Jul 1997 05:00:00 GMT');
    }

    protected function isMediaRoute($request): bool
    {
        return str_starts_with($request->path(), 'media/');
    }
}

namespace App\Core\Media\Config;

class CacheConfig
{
    public int $imageCacheTTL = 604800; // 1 week
    public int $videoCacheTTL = 86400;  // 1 day
    public int $documentCacheTTL = 86400; // 1 day
    public int $browserCacheTTL = 3600;  // 1 hour

    public array $preWarmConfigs = [
        ['width' => 100, 'height' => 100],
        ['width' => 300, 'height' => 300],
        ['width' => 800, 'height' => 800]
    ];

    public int $maxCacheSize = 10 * 1024 * 1024 * 1024; // 10GB
    public bool $persistCache = true;
    public string $cacheDriver = 'redis';

    public function __construct(array $config = [])
    {
        foreach ($config as $key => $value) {
            if (property_exists($this, $key)) {
                $this->{$key} = $value;
            }
        }
    }
}

namespace App\Core\Media\Cache\Jobs;

class CacheMaintenanceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function handle(MediaCacheManager $cacheManager): void
    {
        // Clean expired cache entries
        $this->cleanExpiredCache();

        // Enforce cache size limits
        $this->enforceSizeLimits();

        // Pre-warm cache for frequently accessed media
        $this->preWarmFrequentlyAccessed();
    }

    protected function cleanExpiredCache(): void
    {
        $expired = Cache::tags(['media'])->getExpired();
        
        foreach ($expired as $key) {
            Cache::forget($key);
        }
    }

    protected function enforceSizeLimits(): void
    {
        $currentSize = $this->calculateCacheSize();
        $maxSize = config('media.cache.max_size');

        if ($currentSize > $maxSize) {
            $this->pruneCache($currentSize - $maxSize);
        }
    }

    protected function preWarmFrequentlyAccessed(): void
    {
        $frequentMedia = Media::withCount('accesses')
            ->orderBy('accesses_count', 'desc')
            ->limit(100)
            ->get();

        foreach ($frequentMedia as $media) {
            PreWarmCacheJob::dispatch($media);
        }
    }
}
