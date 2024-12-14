<?php

namespace App\Core\Routing;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Exception\RoutingException;
use Psr\Log\LoggerInterface;
use Illuminate\Support\Facades\Cache;

class RouterManager implements RouterManagerInterface
{
    private SecurityManagerInterface $security;
    private LoggerInterface $logger;
    private array $config;
    private array $routes = [];
    private array $middlewares = [];

    public function __construct(
        SecurityManagerInterface $security,
        LoggerInterface $logger,
        array $config = []
    ) {
        $this->security = $security;
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    public function registerRoute(string $method, string $path, array $handler): void
    {
        $routeId = $this->generateRouteId($method, $path);

        try {
            $this->security->validateSecureOperation('route:register', [
                'route_id' => $routeId,
                'method' => $method,
                'path' => $path
            ]);

            $this->validateRoute($method, $path, $handler);
            $this->storeRoute($routeId, $method, $path, $handler);
            
            $this->logRouteRegistration($routeId);

        } catch (\Exception $e) {
            $this->handleRoutingFailure($routeId, 'register', $e);
            throw new RoutingException('Route registration failed', 0, $e);
        }
    }

    public function attachMiddleware(string $path, array $middleware): void
    {
        $middlewareId = $this->generateMiddlewareId($path);

        try {
            $this->security->validateSecureOperation('route:middleware', [
                'middleware_id' => $middlewareId,
                'path' => $path
            ]);

            $this->validateMiddleware($middleware);
            $this->storeMiddleware($middlewareId, $path, $middleware);
            
            $this->logMiddlewareAttachment($middlewareId);

        } catch (\Exception $e) {
            $this->handleRoutingFailure($middlewareId, 'middleware', $e);
            throw new RoutingException('Middleware attachment failed', 0, $e);
        }
    }

    public function resolveRoute(string $method, string $path): RouteResult
    {
        $requestId = $this->generateRequestId();

        try {
            $this->security->validateSecureOperation('route:resolve', [
                'request_id' => $requestId,
                'method' => $method,
                'path' => $path
            ]);

            $route = $this->findRoute($method, $path);
            $middlewares = $this->resolveMiddlewares($path);
            
            return new RouteResult([
                'route' => $route,
                'middlewares' => $middlewares,
                'request_id' => $requestId
            ]);

        } catch (\Exception $e) {
            $this->handleRoutingFailure($requestId, 'resolve', $e);
            throw new RoutingException('Route resolution failed', 0, $e);
        }
    }

    private function validateRoute(string $method, string $path, array $handler): void
    {
        if (!in_array($method, $this->config['allowed_methods'])) {
            throw new RoutingException('Invalid HTTP method');
        }

        if (!$this->isValidPath($path)) {
            throw new RoutingException('Invalid route path');
        }

        if (!isset($handler['controller'], $handler['action'])) {
            throw new RoutingException('Invalid route handler');
        }
    }

    private function validateMiddleware(array $middleware): void
    {
        foreach ($middleware as $m) {
            if (!isset($m['class'], $m['priority'])) {
                throw new RoutingException('Invalid middleware configuration');
            }

            if (!class_exists($m['class'])) {
                throw new RoutingException('Middleware class not found');
            }
        }
    }

    private function storeRoute(string $routeId, string $method, string $path, array $handler): void
    {
        $route = [
            'id' => $routeId,
            'method' => $method,
            'path' => $path,
            'handler' => $handler,
            'created_at' => time()
        ];

        $this->routes[$routeId] = $route;
        $this->cacheRoute($route);
    }

    private function storeMiddleware(string $middlewareId, string $path, array $middleware): void
    {
        $this->middlewares[$path][] = [
            'id' => $middlewareId,
            'middleware' => $middleware,
            'created_at' => time()
        ];

        $this->cacheMiddleware($path, $this->middlewares[$path]);
    }

    private function findRoute(string $method, string $path): array
    {
        $routes = $this->getRoutesFromCache();
        
        foreach ($routes as $route) {
            if ($this->matchRoute($route, $method, $path)) {
                return $route;
            }
        }

        throw new RoutingException('Route not found');
    }

    private function resolveMiddlewares(string $path): array
    {
        $middlewares = [];
        $paths = $this->getMiddlewarePaths();

        foreach ($paths as $pattern) {
            if ($this->matchPath($pattern, $path)) {
                $middlewares = array_merge(
                    $middlewares,
                    $this->getMiddlewareFromCache($pattern)
                );
            }
        }

        return $this->sortMiddlewares($middlewares);
    }

    private function matchRoute(array $route, string $method, string $path): bool
    {
        return $route['method'] === $method && 
               $this->matchPath($route['path'], $path);
    }

    private function matchPath(string $pattern, string $path): bool
    {
        $pattern = preg_quote($pattern, '/');
        $pattern = str_replace('\\*', '.*', $pattern);
        return (bool)preg_match('/^' . $pattern . '$/', $path);
    }

    private function sortMiddlewares(array $middlewares): array
    {
        usort($middlewares, function($a, $b) {
            return $b['middleware']['priority'] - $a['middleware']['priority'];
        });

        return $middlewares;
    }

    private function isValidPath(string $path): bool
    {
        return preg_match($this->config['path_pattern'], $path);
    }

    private function generateRouteId(string $method, string $path): string
    {
        return md5($method . ':' . $path);
    }

    private function generateMiddlewareId(string $path): string
    {
        return md5('middleware:' . $path . ':' . uniqid());
    }

    private function generateRequestId(): string
    {
        return uniqid('request_', true);
    }

    private function cacheRoute(array $route): void
    {
        Cache::tags(['routes'])->put(
            'route:' . $route['id'],
            $route,
            $this->config['cache_ttl']
        );
    }

    private function cacheMiddleware(string $path, array $middlewares): void
    {
        Cache::tags(['middlewares'])->put(
            'middleware:' . md5($path),
            $middlewares,
            $this->config['cache_ttl']
        );
    }

    private function getDefaultConfig(): array
    {
        return [
            'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
            'path_pattern' => '/^[a-zA-Z0-9\/_-]+$/',
            'cache_ttl' => 3600,
            'max_middlewares' => 10
        ];
    }

    private function handleRoutingFailure(string $id, string $operation, \Exception $e): void
    {
        $this->logger->error('Routing operation failed', [
            'id' => $id,
            'operation' => $operation,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}
