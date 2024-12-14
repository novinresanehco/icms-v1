<?php

namespace App\Core\Template\Assets;

use App\Core\Template\Exceptions\AssetException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AssetManager
{
    private Collection $assets;
    private array $manifestCache = [];
    private ?string $cdnUrl = null;
    
    public function __construct()
    {
        $this->assets = new Collection();
        $this->cdnUrl = config('assets.cdn_url');
    }

    /**
     * Register a new asset
     *
     * @param string $path
     * @param string $type
     * @param array $attributes
     * @return void
     */
    public function register(string $path, string $type, array $attributes = []): void
    {
        $this->assets->push([
            'path' => $path,
            'type' => $type,
            'attributes' => $attributes,
            'version' => $this->getAssetVersion($path)
        ]);
    }

    /**
     * Add multiple assets at once
     *
     * @param array $assets
     * @return void
     */
    public function registerBatch(array $assets): void
    {
        foreach ($assets as $asset) {
            $this->register(
                $asset['path'],
                $asset['type'],
                $asset['attributes'] ?? []
            );
        }
    }

    /**
     * Get all registered assets of a specific type
     *
     * @param string $type
     * @return Collection
     */
    public function getAssets(string $type): Collection
    {
        return $this->assets->filter(function ($asset) use ($type) {
            return $asset['type'] === $type;
        });
    }

    /**
     * Generate HTML for all assets of a type
     *
     * @param string $type
     * @return string
     */
    public function render(string $type): string
    {
        return $this->getAssets($type)
            ->map(function ($asset) use ($type) {
                return $this->renderAsset($asset, $type);
            })
            ->implode("\n");
    }

    /**
     * Optimize and combine assets of a type
     *
     * @param string $type
     * @return string
     */
    public function optimize(string $type): string
    {
        $assets = $this->getAssets($type);
        $combined = '';

        foreach ($assets as $asset) {
            $content = $this->getAssetContent($asset['path']);
            $combined .= $this->optimizeAssetContent($content, $type) . "\n";
        }

        $hash = md5($combined);
        $path = "public/compiled/{$type}/{$hash}.{$type}";
        
        Storage::put($path, $combined);
        
        return asset($path);
    }

    /**
     * Render a single asset
     *
     * @param array $asset
     * @param string $type
     * @return string
     */
    protected function renderAsset(array $asset, string $type): string
    {
        $url = $this->getAssetUrl($asset['path']);
        $attributes = $this->renderAttributes($asset['attributes']);

        switch ($type) {
            case 'css':
                return "<link rel=\"stylesheet\" href=\"{$url}\"{$attributes}>";
            case 'js':
                return "<script src=\"{$url}\"{$attributes}></script>";
            default:
                throw new AssetException("Unsupported asset type: {$type}");
        }
    }

    /**
     * Get the asset URL (local or CDN)
     *
     * @param string $path
     * @return string
     */
    protected function getAssetUrl(string $path): string
    {
        if ($this->cdnUrl && !app()->isLocal()) {
            return $this->cdnUrl . '/' . ltrim($path, '/');
        }

        return asset($path);
    }

    /**
     * Get asset version from manifest
     *
     * @param string $path
     * @return string|null
     */
    protected function getAssetVersion(string $path): ?string
    {
        $manifest = $this->loadManifest();
        return $manifest[$path] ?? null;
    }

    /**
     * Load the asset manifest file
     *
     * @return array
     */
    protected function loadManifest(): array
    {
        if (empty($this->manifestCache)) {
            $manifestPath = public_path('mix-manifest.json');
            
            if (file_exists($manifestPath)) {
                $this->manifestCache = json_decode(
                    file_get_contents($manifestPath),
                    true
                );
            }
        }

        return $this->manifestCache;
    }

    /**
     * Optimize asset content based on type
     *
     * @param string $content
     * @param string $type
     * @return string
     */
    protected function optimizeAssetContent(string $content, string $type): string
    {
        switch ($type) {
            case 'css':
                return $this->optimizeCss($content);
            case 'js':
                return $this->optimizeJs($content);
            default:
                return $content;
        }
    }

    /**
     * Optimize CSS content
     *
     * @param string $content
     * @return string
     */
    protected function optimizeCss(string $content): string
    {
        // Remove comments
        $content = preg_replace('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $content);
        
        // Remove space after colons
        $content = str_replace(': ', ':', $content);
        
        // Remove whitespace
        $content = str_replace(["\r\n", "\r", "\n", "\t"], '', $content);
        
        return trim($content);
    }

    /**
     * Optimize JavaScript content
     *
     * @param string $content
     * @return string
     */
    protected function optimizeJs(string $content): string
    {
        if (app()->environment('production')) {
            // Use a minifier library in production
            return \JShrink\Minifier::minify($content);
        }

        return $content;
    }

    /**
     * Render HTML attributes
     *
     * @param array $attributes
     * @return string
     */
    protected function renderAttributes(array $attributes): string
    {
        return collect($attributes)
            ->map(function ($value, $key) {
                if (is_bool($value)) {
                    return $value ? $key : '';
                }
                return "{$key}=\"{$value}\"";
            })
            ->filter()
            ->implode(' ');
    }
}

class AssetCollection
{
    private Collection $items;
    private array $dependencies = [];

    public function __construct()
    {
        $this->items = new Collection();
    }

    /**
     * Add an asset with dependencies
     *
     * @param string $path
     * @param array $dependencies
     * @return void
     */
    public function add(string $path, array $dependencies = []): void
    {
        $this->items->push($path);
        $this->dependencies[$path] = $dependencies;
    }

    /**
     * Get sorted assets respecting dependencies
     *
     * @return array
     */
    public function getSorted(): array
    {
        $sorted = [];
        $visited = [];

        foreach ($this->items as $item) {
            $this->sortAsset($item, $sorted, $visited);
        }

        return $sorted;
    }

    /**
     * Sort asset considering dependencies
     *
     * @param string $asset
     * @param array $sorted
     * @param array $visited
     * @return void
     */
    protected function sortAsset(string $asset, array &$sorted, array &$visited): void
    {
        if (isset($visited[$asset])) {
            return;
        }

        $visited[$asset] = true;

        foreach ($this->dependencies[$asset] ?? [] as $dependency) {
            $this->sortAsset($dependency, $sorted, $visited);
        }

        $sorted[] = $asset;
    }
}

// Service Provider Registration
namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Core\Template\Assets\AssetManager;

class AssetServiceProvider extends ServiceProvider
{
    /**
     * Register services
     *
     * @return void
     */
    public function register(): void
    {
        $this->app->singleton(AssetManager::class);
    }

    /**
     * Bootstrap services
     *
     * @return void
     */
    public function boot(): void
    {
        $manager = $this->app->make(AssetManager::class);

        // Register core assets
        $manager->registerBatch([
            [
                'path' => 'css/app.css',
                'type' => 'css',
                'attributes' => ['media' => 'all']
            ],
            [
                'path' => 'js/app.js',
                'type' => 'js',
                'attributes' => ['defer' => true]
            ]
        ]);
    }
}
