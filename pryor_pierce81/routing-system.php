namespace App\Core\Routing;

class Router implements RouterInterface
{
    private SecurityManager $security;
    private CacheManager $cache;
    private MetricsCollector $metrics;
    private ValidatorService $validator;
    private EventDispatcher $events;
    private array $routes = [];

    public function __construct(
        SecurityManager $security,
        CacheManager $cache,
        MetricsCollector $metrics,
        ValidatorService $validator,
        EventDispatcher $events
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->metrics = $metrics;
        $this->validator = $validator;
        $this->events = $events;
    }

    public function dispatch(Request $request): Response
    {
        $startTime = microtime(true);

        try {
            return $this->security->executeCriticalOperation(
                new RouteDispatchOperation(
                    $request,
                    $this->findRoute($request),
                    $this->validator
                ),
                SecurityContext::fromRequest()
            );
        } finally {
            $this->metrics->timing(
                'router.dispatch.duration',
                microtime(true) - $startTime
            );
        }
    }

    public function register(string $method, string $path, $handler): Route
    {
        $this->validateRoute($method, $path, $handler);

        $route = new Route($method, $path, $handler);
        $this->routes[] = $route;
        $this->clearRouteCache();

        return $route;
    }

    public function group(array $attributes, callable $callback): void
    {
        $this->validateGroupAttributes($attributes);

        $originalRoutes = $this->routes;
        $callback($this);
        
        $newRoutes = array_diff($this->routes, $originalRoutes);
        foreach ($newRoutes as $route) {
            $route->addAttributes($attributes);
        }

        $this->clearRouteCache();
    }

    private function findRoute(Request $request): Route
    {
        $cacheKey = $this->getRouteCacheKey($request);

        return $this->cache->remember($cacheKey, 3600, function () use ($request) {
            foreach ($this->getCompiledRoutes() as $route) {
                if ($this->routeMatches($route, $request)) {
                    return $route;
                }
            }

            throw new RouteNotFoundException('No matching route found');
        });
    }

    private function routeMatches(Route $route, Request $request): bool
    {
        if ($route->getMethod() !== $request->getMethod()) {
            return false;
        }

        $pattern = $this->compileRoutePattern($route->getPath());
        return preg_match($pattern, $request->getPathInfo());
    }

    private function compileRoutePattern(string $path): string
    {
        return $this->cache->remember(
            "route_pattern.{$path}",
            3600,
            function () use ($path) {
                $pattern = preg_replace(
                    '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
                    '(?P<$1>[^/]+)',
                    $path
                );
                return '#^' . $pattern . '$#';
            }
        );
    }

    private function validateRoute(string $method, string $path, $handler): void
    {
        if (!in_array($method, ['GET', 'POST', 'PUT', 'DELETE', 'PATCH'])) {
            throw new InvalidRouteException('Invalid HTTP method');
        }

        if (!$this->validator->validatePath($path)) {
            throw new InvalidRouteException('Invalid route path');
        }

        if (!is_callable($handler) && !is_array($handler)) {
            throw new InvalidRouteException('Invalid route handler');
        }
    }

    private function validateGroupAttributes(array $attributes): void
    {
        $allowed = ['prefix', 'middleware', 'namespace', 'domain'];
        
        foreach (array_keys($attributes) as $key) {
            if (!in_array($key, $allowed)) {
                throw new InvalidArgumentException("Invalid group attribute: {$key}");
            }
        }
    }

    private function getRouteCacheKey(Request $request): string
    {
        return sprintf(
            'route.%s.%s',
            $request->getMethod(),
            md5($request->getPathInfo())
        );
    }

    private function clearRouteCache(): void
    {
        $this->cache->tags(['routes'])->flush();
    }

    private function getCompiledRoutes(): array
    {
        return $this->cache->remember('compiled_routes', 3600, function () {
            return array_map(function (Route $route) {
                return $route->compile();
            }, $this->routes);
        });
    }

    public function middleware(string|array $middleware): self
    {
        $middleware = (array) $middleware;
        
        foreach ($middleware as $m) {
            if (!class_exists($m)) {
                throw new InvalidMiddlewareException("Middleware not found: {$m}");
            }
        }

        $this->routes[array_key_last($this->routes)]->middleware($middleware);
        return $this;
    }

    public function name(string $name): self
    {
        if ($this->hasNamedRoute($name)) {
            throw new RouteNameConflictException("Route name already exists: {$name}");
        }

        $this->routes[array_key_last($this->routes)]->name($name);
        return $this;
    }

    private function hasNamedRoute(string $name): bool
    {
        foreach ($this->routes as $route) {
            if ($route->getName() === $name) {
                return true;
            }
        }
        return false;
    }

    public function getRouteByName(string $name): ?Route
    {
        return $this->cache->remember(
            "route_name.{$name}",
            3600,
            function () use ($name) {
                foreach ($this->routes as $route) {
                    if ($route->getName() === $name) {
                        return $route;
                    }
                }
                return null;
            }
        );
    }
}
