<?php

namespace App\Core\Template\Routing;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use App\Core\Template\Exceptions\RouteException;

class TemplateRouteManager
{
    private Collection $routes;
    private RouteCache $cache;
    private RouteCompiler $compiler;
    private RouteMatcher $matcher;
    private array $config;

    public function __construct(
        RouteCache $cache,
        RouteCompiler $compiler,
        RouteMatcher $matcher,
        array $config = []
    ) {
        $this->routes = new Collection();
        $this->cache = $cache;
        $this->compiler = $compiler;
        $this->matcher = $matcher;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    /**
     * Register a template route
     *
     * @param string $pattern
     * @param array $options
     * @return TemplateRoute
     */
    public function register(string $pattern, array $options = []): TemplateRoute
    {
        $route = new TemplateRoute($pattern, $options);
        $this->routes->put($route->getName(), $route);
        
        // Clear route cache
        $this->cache->clear();
        
        return $route;
    }

    /**
     * Match request to route
     *
     * @param string $path
     * @return TemplateRoute
     * @throws RouteException
     */
    public function match(string $path): TemplateRoute
    {
        // Check cache first
        if ($cached = $this->cache->get($path)) {
            return $cached;
        }

        // Find matching route
        $route = $this->matcher->match($path, $this->routes);
        
        // Cache the result
        $this->cache->put($path, $route);
        
        return $route;
    }

    /**
     * Generate URL for route
     *
     * @param string $name
     * @param array $params
     * @return string
     */
    public function generateUrl(string $name, array $params = []): string
    {
        $route = $this->routes->get($name);
        
        if (!$route) {
            throw new RouteException("Route not found: {$name}");
        }

        return $this->compiler->compile($route, $params);
    }

    /**
     * Get default configuration
     *
     * @return array
     */
    protected function getDefaultConfig(): array
    {
        return [
            'cache_enabled' => true,
            'cache_ttl' => 3600,
            'strict_matching' => true,
            'trailing_slash' => false
        ];
    }
}

class TemplateRoute
{
    private string $pattern;
    private string $name;
    private array $parameters;
    private array $defaults;
    private array $requirements;
    private array $options;

    public function __construct(string $pattern, array $options = [])
    {
        $this->pattern = $pattern;
        $this->name = $options['name'] ?? $this->generateName($pattern);
        $this->parameters = $options['parameters'] ?? [];
        $this->defaults = $options['defaults'] ?? [];
        $this->requirements = $options['requirements'] ?? [];
        $this->options = $options;
    }

    /**
     * Get route pattern
     *
     * @return string
     */
    public function getPattern(): string
    {
        return $this->pattern;
    }

    /**
     * Get route name
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get route parameters
     *
     * @return array
     */
    public function getParameters(): array
    {
        return $this->parameters;
    }

    /**
     * Get parameter defaults
     *
     * @return array
     */
    public function getDefaults(): array
    {
        return $this->defaults;
    }

    /**
     * Get parameter requirements
     *
     * @return array
     */
    public function getRequirements(): array
    {
        return $this->requirements;
    }

    /**
     * Get route options
     *
     * @return array
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * Generate route name from pattern
     *
     * @param string $pattern
     * @return string
     */
    protected function generateName(string $pattern): string
    {
        return md5($pattern);
    }
}

class RouteCompiler
{
    /**
     * Compile route pattern with parameters
     *
     * @param TemplateRoute $route
     * @param array $params
     * @return string
     */
    public function compile(TemplateRoute $route, array $params = []): string
    {
        $pattern = $route->getPattern();
        $defaults = $route->getDefaults();
        
        // Replace named parameters
        foreach ($route->getParameters() as $param) {
            $value = $params[$param] ?? $defaults[$param] ?? null;
            
            if ($value === null) {
                throw new RouteException("Missing required parameter: {$param}");
            }

            $pattern = str_replace("{{$param}}", $value, $pattern);
        }

        // Add query string for extra parameters
        $queryParams = array_diff_key($params, array_flip($route->getParameters()));
        if (!empty($queryParams)) {
            $pattern .= '?' . http_build_query($queryParams);
        }

        return $pattern;
    }
}

class RouteMatcher
{
    /**
     * Match path against routes
     *
     * @param string $path
     * @param Collection $routes
     * @return TemplateRoute
     */
    public function match(string $path, Collection $routes): TemplateRoute
    {
        foreach ($routes as $route) {
            if ($this->matchRoute($path, $route)) {
                return $route;
            }
        }

        throw new RouteException("No matching route found for path: {$path}");
    }

    /**
     * Match single route
     *
     * @param string $path
     * @param TemplateRoute $route
     * @return bool
     */
    protected function matchRoute(string $path, TemplateRoute $route): bool
    {
        $pattern = $this->convertToRegex($route->getPattern());
        return preg_match($pattern, $path) === 1;
    }

    /**
     * Convert route pattern to regex
     *
     * @param string $pattern
     * @return string
     */
    protected function convertToRegex(string $pattern): string
    {
        $pattern = preg_quote($pattern, '/');
        
        // Replace parameter placeholders with regex
        $pattern = preg_replace('/\\\{(\w+)\\\}/', '(?P<$1>[^/]+)', $pattern);
        
        return '/^' . $pattern . '$/';
    }
}

class RouteCache
{
    private string $prefix;
    private int $ttl;

    public function __construct(string $prefix = 'route_cache:', int $ttl = 3600)
    {
        $this->prefix = $prefix;
        $this->ttl = $ttl;
    }

    /**
     * Get cached route
     *
     * @param string $path
     * @return TemplateRoute|null
     */
    public function get(string $path): ?TemplateRoute
    {
        return Cache::get($this->getCacheKey($path));
    }

    /**
     * Cache route
     *
     * @param string $path
     * @param TemplateRoute $route
     * @return void
     */
    public function put(string $path, TemplateRoute $route): void
    {
        Cache::put($this->getCacheKey($path), $route, $this->ttl);
    }

    /**
     * Clear route cache
     *
     * @return void
     */
    public function clear(): void
    {
        Cache::tags(['routes'])->flush();
    }

    /**
     * Generate cache key
     *
     * @param string $path
     * @return string
     */
    protected function getCacheKey(string $path): string
    {
        return $this->prefix . md5($path);
    }
}

// Service Provider Registration
namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Core\Template\Routing\TemplateRouteManager;
use App\Core\Template\Routing\RouteCache;
use App\Core\Template\Routing\RouteCompiler;
use App\Core\Template\Routing\RouteMatcher;

class TemplateRouteServiceProvider extends ServiceProvider
{
    /**
     * Register services
     *
     * @return void
     */
    public function register(): void
    {
        $this->app->singleton(TemplateRouteManager::class, function ($app) {
            return new TemplateRouteManager(
                new RouteCache(),
                new RouteCompiler(),
                new RouteMatcher(),
                config('template.routing')
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
        // Register template routes
        $router = $this->app->make(TemplateRouteManager::class);

        // Register default template routes
        $router->register('/template/{name}', [
            'name' => 'template.show',
            'defaults' => ['format' => 'html']
        ]);
    }
}
