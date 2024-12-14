<?php

namespace App\Core\Template\Rendering;

use App\Core\Template\Exceptions\RenderException;
use App\Core\Cache\CacheManager;
use Illuminate\Contracts\View\Factory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ViewRenderer
{
    private Factory $viewFactory;
    private CacheManager $cache;
    private Collection $middleware;
    private array $globalData = [];

    public function __construct(Factory $viewFactory, CacheManager $cache)
    {
        $this->viewFactory = $viewFactory;
        $this->cache = $cache;
        $this->middleware = new Collection();
    }

    /**
     * Render a view with optional caching
     *
     * @param string $view
     * @param array $data
     * @param array $options
     * @return string
     * @throws RenderException
     */
    public function render(string $view, array $data = [], array $options = []): string
    {
        try {
            $data = $this->prepareViewData($data);
            
            if ($this->shouldCache($options)) {
                return $this->renderCached($view, $data, $options);
            }

            return $this->renderView($view, $data);
        } catch (\Exception $e) {
            Log::error('View rendering failed', [
                'view' => $view,
                'error' => $e->getMessage()
            ]);
            throw new RenderException("Failed to render view: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Add global data available to all views
     *
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function share(string $key, $value): void
    {
        $this->globalData[$key] = $value;
    }

    /**
     * Add a rendering middleware
     *
     * @param RenderMiddleware $middleware
     * @return void
     */
    public function addMiddleware(RenderMiddleware $middleware): void
    {
        $this->middleware->push($middleware);
    }

    /**
     * Render view with caching
     *
     * @param string $view
     * @param array $data
     * @param array $options
     * @return string
     */
    protected function renderCached(string $view, array $data, array $options): string
    {
        $cacheKey = $this->generateCacheKey($view, $data);
        $ttl = $options['cache_ttl'] ?? 3600;

        return $this->cache->remember($cacheKey, $ttl, function () use ($view, $data) {
            return $this->renderView($view, $data);
        });
    }

    /**
     * Render the actual view
     *
     * @param string $view
     * @param array $data
     * @return string
     */
    protected function renderView(string $view, array $data): string
    {
        $content = $this->viewFactory->make($view, $data)->render();
        
        return $this->applyMiddleware($content, $data);
    }

    /**
     * Apply rendering middleware
     *
     * @param string $content
     * @param array $data
     * @return string
     */
    protected function applyMiddleware(string $content, array $data): string
    {
        return $this->middleware->reduce(
            function ($content, $middleware) use ($data) {
                return $middleware->handle($content, $data);
            },
            $content
        );
    }

    /**
     * Prepare view data by merging with global data
     *
     * @param array $data
     * @return array
     */
    protected function prepareViewData(array $data): array
    {
        return array_merge($this->globalData, $data);
    }

    /**
     * Generate cache key for view
     *
     * @param string $view
     * @param array $data
     * @return string
     */
    protected function generateCacheKey(string $view, array $data): string
    {
        return sprintf(
            'view:%s:%s',
            str_replace(['/', '.'], '_', $view),
            md5(serialize($data))
        );
    }

    /**
     * Determine if view should be cached
     *
     * @param array $options
     * @return bool
     */
    protected function shouldCache(array $options): bool
    {
        return ($options['cache'] ?? false) && 
               !app()->isLocal() && 
               request()->isMethod('GET');
    }
}

abstract class RenderMiddleware
{
    /**
     * Handle the rendered content
     *
     * @param string $content
     * @param array $data
     * @return string
     */
    abstract public function handle(string $content, array $data): string;
}

class MinifyHtmlMiddleware extends RenderMiddleware
{
    /**
     * Handle the rendered content
     *
     * @param string $content
     * @param array $data
     * @return string
     */
    public function handle(string $content, array $data): string
    {
        if (!config('template.minify', false)) {
            return $content;
        }

        return preg_replace(
            ['/\>[^\S ]+/s', '/[^\S ]+\</s', '/(\s)+/s'],
            ['>', '<', '\\1'],
            $content
        );
    }
}

class OptimizeScriptsMiddleware extends RenderMiddleware
{
    /**
     * Handle the rendered content
     *
     * @param string $content
     * @param array $data
     * @return string
     */
    public function handle(string $content, array $data): string
    {
        if (!config('template.optimize_scripts', false)) {
            return $content;
        }

        // Move scripts to end of body
        preg_match_all('/<script[^>]*>(.*?)<\/script>/is', $content, $matches);
        
        $scripts = implode("\n", $matches[0]);
        $content = preg_replace('/<script[^>]*>(.*?)<\/script>/is', '', $content);
        
        return str_replace('</body>', $scripts . '</body>', $content);
    }
}

class SecurityHeadersMiddleware extends RenderMiddleware
{
    /**
     * Handle the rendered content
     *
     * @param string $content
     * @param array $data
     * @return string
     */
    public function handle(string $content, array $data): string
    {
        $headers = [
            'X-Frame-Options' => 'SAMEORIGIN',
            'X-XSS-Protection' => '1; mode=block',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin'
        ];

        foreach ($headers as $header => $value) {
            header("$header: $value");
        }

        return $content;
    }
}

// Service Provider Registration
namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Core\Template\Rendering\ViewRenderer;
use App\Core\Template\Rendering\MinifyHtmlMiddleware;
use App\Core\Template\Rendering\OptimizeScriptsMiddleware;
use App\Core\Template\Rendering\SecurityHeadersMiddleware;

class ViewRendererServiceProvider extends ServiceProvider
{
    /**
     * Register services
     *
     * @return void
     */
    public function register(): void
    {
        $this->app->singleton(ViewRenderer::class, function ($app) {
            $renderer = new ViewRenderer(
                $app['view'],
                $app['cache']
            );

            // Add default middleware
            $renderer->addMiddleware(new MinifyHtmlMiddleware());
            $renderer->addMiddleware(new OptimizeScriptsMiddleware());
            $renderer->addMiddleware(new SecurityHeadersMiddleware());

            return $renderer;
        });
    }

    /**
     * Bootstrap services
     *
     * @return void
     */
    public function boot(): void
    {
        $renderer = $this->app->make(ViewRenderer::class);

        // Add global view data
        $renderer->share('app_name', config('app.name'));
        $renderer->share('app_version', config('app.version'));
    }
}
