<?php

namespace App\Core\Template\Resources;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use App\Core\Template\Exceptions\ResourceException;

class ResourceManager
{
    private Collection $resources;
    private ResourceLoader $loader;
    private ResourceOptimizer $optimizer;
    private ResourceCache $cache;
    private array $config;

    public function __construct(
        ResourceLoader $loader,
        ResourceOptimizer $optimizer,
        ResourceCache $cache,
        array $config = []
    ) {
        $this->resources = new Collection();
        $this->loader = $loader;
        $this->optimizer = $optimizer;
        $this->cache = $cache;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    /**
     * Register resource
     *
     * @param string $type
     * @param string $path
     * @param array $options
     * @return Resource
     */
    public function register(string $type, string $path, array $options = []): Resource
    {
        $resource = new Resource($type, $path, $options);
        $this->resources->push($resource);

        if ($options['preload'] ?? false) {
            $this->preloadResource($resource);
        }

        return $resource;
    }

    /**
     * Get resource by path
     *
     * @param string $path
     * @return Resource|null
     */
    public function get(string $path): ?Resource
    {
        return $this->resources->first(function ($resource) use ($path) {
            return $resource->getPath() === $path;
        });
    }

    /**
     * Get resources by type
     *
     * @param string $type
     * @return Collection
     */
    public function getByType(string $type): Collection
    {
        return $this->resources->filter(function ($resource) use ($type) {
            return $resource->getType() === $type;
        });
    }

    /**
     * Load resource content
     *
     * @param Resource $resource
     * @return mixed
     */
    public function load(Resource $resource)
    {
        $cacheKey = $this->getCacheKey($resource);

        if ($cached = $this->cache->get($cacheKey)) {
            return $cached;
        }

        $content = $this->loader->load($resource);
        
        if ($this->shouldOptimize($resource)) {
            $content = $this->optimizer->optimize($content, $resource->getType());
        }

        $this->cache->put($cacheKey, $content);
        return $content;
    }

    /**
     * Preload resource
     *
     * @param Resource $resource
     * @return void
     */
    public function preloadResource(Resource $resource): void
    {
        if (!$this->cache->has($this->getCacheKey($resource))) {
            $this->load($resource);
        }
    }

    /**
     * Generate resource tags
     *
     * @param Resource $resource
     * @return string
     */
    public function generateTags(Resource $resource): string
    {
        switch ($resource->getType()) {
            case 'css':
                return $this->generateStyleTag($resource);
            case 'js':
                return $this->generateScriptTag($resource);
            case 'image':
                return $this->generateImageTag($resource);
            default:
                throw new ResourceException("Unsupported resource type: {$resource->getType()}");
        }
    }

    /**
     * Bundle resources of same type
     *
     * @param string $type
     * @return Resource
     */
    public function bundle(string $type): Resource
    {
        $resources = $this->getByType($type);
        $content = '';

        foreach ($resources as $resource) {
            $content .= $this->load($resource) . "\n";
        }

        $bundlePath = "bundles/{$type}-" . md5($content) . ".{$type}";
        Storage::put($bundlePath, $content);

        return $this->register($type, $bundlePath, [
            'bundled' => true,
            'original_resources' => $resources
        ]);
    }

    /**
     * Check if resource exists
     *
     * @param string $path
     * @return bool
     */
    public function exists(string $path): bool
    {
        return $this->resources->contains(function ($resource) use ($path) {
            return $resource->getPath() === $path;
        });
    }

    /**
     * Generate style tag
     *
     * @param Resource $resource
     * @return string
     */
    protected function generateStyleTag(Resource $resource): string
    {
        $path = $this->getPublicPath($resource);
        $attributes = $this->generateAttributes($resource->getOptions());
        return "<link rel=\"stylesheet\" href=\"{$path}\"{$attributes}>";
    }

    /**
     * Generate script tag
     *
     * @param Resource $resource
     * @return string
     */
    protected function generateScriptTag(Resource $resource): string
    {
        $path = $this->getPublicPath($resource);
        $attributes = $this->generateAttributes($resource->getOptions());
        return "<script src=\"{$path}\"{$attributes}></script>";
    }

    /**
     * Generate image tag
     *
     * @param Resource $resource
     * @return string
     */
    protected function generateImageTag(Resource $resource): string
    {
        $path = $this->getPublicPath($resource);
        $attributes = $this->generateAttributes($resource->getOptions());
        return "<img src=\"{$path}\"{$attributes}>";
    }

    /**
     * Generate HTML attributes
     *
     * @param array $options
     * @return string
     */
    protected function generateAttributes(array $options): string
    {
        $attributes = [];

        foreach ($options as $key => $value) {
            if (is_bool($value)) {
                if ($value) {
                    $attributes[] = $key;
                }
            } else {
                $attributes[] = "{$key}=\"{$value}\"";
            }
        }

        return $attributes ? ' ' . implode(' ', $attributes) : '';
    }

    /**
     * Get public path for resource
     *
     * @param Resource $resource
     * @return string
     */
    protected function getPublicPath(Resource $resource): string
    {
        if ($this->isExternalResource($resource->getPath())) {
            return $resource->getPath();
        }

        return asset($resource->getPath());
    }

    /**
     * Check if resource is external
     *
     * @param string $path
     * @return bool
     */
    protected function isExternalResource(string $path): bool
    {
        return str_starts_with($path, 'http://') || str_starts_with($path, 'https://');
    }

    /**
     * Get cache key for resource
     *
     * @param Resource $resource
     * @return string
     */
    protected function getCacheKey(Resource $resource): string
    {
        return "resource:{$resource->getType()}:{$resource->getPath()}";
    }

    /**
     * Check if resource should be optimized
     *
     * @param Resource $resource
     * @return bool
     */
    protected function shouldOptimize(Resource $resource): bool
    {
        if ($resource->getOptions()['skip_optimization'] ?? false) {
            return false;
        }

        return in_array($resource->getType(), $this->config['optimize_types']);
    }

    /**
     * Get default configuration
     *
     * @return array
     */
    protected function getDefaultConfig(): array
    {
        return [
            'optimize_types' => ['css', 'js', 'image'],
            'bundle_enabled' => true,
            'cache_enabled' => true,
            'preload_enabled' => true,
            'cdn_url' => null
        ];
    }
}

class Resource
{
    private string $type;
    private string $path;
    private array $options;

    public function __construct(string $type, string $path, array $options = [])
    {
        $this->type = $type;
        $this->path = $path;
        $this->options = $options;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getOptions(): array
    {
        return $this->options;
    }
}

class ResourceLoader
{
    private array $loaders = [];

    public function __construct()
    {
        $this->registerDefaultLoaders();
    }

    /**
     * Register loader for resource type
     *
     * @param string $type
     * @param callable $loader
     * @return void
     */
    public function registerLoader(string $type, callable $loader): void
    {
        $this->loaders[$type] = $loader;
    }

    /**
     * Load resource content
     *
     * @param Resource $resource
     * @return mixed
     */
    public function load(Resource $resource)
    {
        $loader = $this->loaders[$resource->getType()] ?? null;

        if (!$loader) {
            throw new ResourceException("No loader found for type: {$resource->getType()}");
        }

        return call_user_func($loader, $resource);
    }

    /**
     * Register default loaders
     *
     * @return void
     */
    private function registerDefaultLoaders(): void
    {
        // File loader
        $this->registerLoader('file', function (Resource $resource) {
            return file_get_contents($resource->getPath());
        });

        // Remote loader
        $this->registerLoader('remote', function (Resource $resource) {
            return file_get_contents($resource->getPath());
        });

        // Storage loader
        $this->registerLoader('storage', function (Resource $resource) {
            return Storage::get($resource->getPath());
        });
    }
}

// Service Provider Registration
namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Core\Template\Resources\ResourceManager;
use App\Core\Template\Resources\ResourceLoader;
use App\Core\Template\Resources\ResourceOptimizer;
use App\Core\Template\Resources\ResourceCache;

class ResourceServiceProvider extends ServiceProvider
{
    /**
     * Register services
     *
     * @return void
     */
    public function register(): void
    {
        $this->app->singleton(ResourceManager::class, function ($app) {
            return new ResourceManager(
                new ResourceLoader(),
                new ResourceOptimizer(),
                new ResourceCache(),
                config('template.resources')
            );
        });
    }

    /**
     * Bootstrap services
     *
     * @return void
     */
    public function boot(): void
    {
        // Add Blade directive
        $this->app['blade.compiler']->directive('resource', function ($expression) {
            return "<?php echo app(App\Core\Template\Resources\ResourceManager::class)->generateTags($expression); ?>";
        });
    }
}
