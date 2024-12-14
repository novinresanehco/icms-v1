<?php

namespace App\Core\Routing;

use App\Core\Security\SecurityManager;
use App\Core\Monitoring\SystemMonitor;
use App\Core\Cache\CacheManager;
use App\Core\Exceptions\RouteException;
use Illuminate\Http\Request;

class RouteProtection implements RouteProtectionInterface
{
    private SecurityManager $security;
    private SystemMonitor $monitor;
    private CacheManager $cache;
    private array $config;

    public function __construct(
        SecurityManager $security,
        SystemMonitor $monitor,
        CacheManager $cache,
        array $config
    ) {
        $this->security = $security;
        $this->monitor = $monitor;
        $this->cache = $cache;
        $this->config = $config;
    }

    public function validateRoute(string $route, Request $request): bool
    {
        $monitoringId = $this->monitor->startOperation('route_validation');
        
        try {
            $this->validateRouteDefinition($route);
            $this->validateRouteAccess($route, $request);
            $this->validateRouteRequirements($route, $request);
            $this->validateRateLimit($route, $request);
            
            $this->monitor->recordSuccess($monitoringId);
            
            return true;
            
        } catch (\Exception $e) {
            $this->monitor->recordFailure($monitoringId, $e);
            throw new RouteException('Route validation failed: ' . $e->getMessage(), 0, $e);
        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    public function protectRoute(string $route, array $config = []): void
    {
        $monitoringId = $this->monitor->startOperation('route_protection');
        
        try {
            $this->validateProtectionConfig($config);
            
            $protection = array_merge(
                $this->getDefaultProtection(),
                $config
            );
            
            $this->applyRouteProtection($route, $protection);
            $this->cacheRouteProtection($route, $protection);
            
            $this->monitor->recordSuccess($monitoringId);
            
        } catch (\Exception $e) {
            $this->monitor->recordFailure($monitoringId, $e);
            throw new RouteException('Route protection failed: ' . $e->getMessage(), 0, $e);
        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    private function validateRouteDefinition(string $route): void
    {
        if (!$this->isValidRoute($route)) {
            throw new RouteException('Invalid route definition');
        }

        if ($this->isBlockedRoute($route)) {
            throw new RouteException('Route is blocked');
        }
    }

    private function validateRouteAccess(string $route, Request $request): void
    {
        $user = $this->security->getCurrentUser();
        
        if (!$this->security->validateRouteAccess($user, $route)) {
            throw new RouteException('Route access denied');
        }

        if (!$this->validateMethodAccess($route, $request->method())) {
            throw new RouteException('HTTP method not allowed');
        }
    }

    private function validateRouteRequirements(string $route, Request $request): void
    {
        $requirements = $this->getRouteRequirements($route);
        
        foreach ($requirements as $requirement => $validator) {
            if (!$validator($request)) {
                throw new RouteException("Route requirement not met: {$requirement}");
            }
        }
    }

    private function validateRateLimit(string $route, Request $request): void
    {
        $limit = $this->getRouteRateLimit($route);
        $key = $this->getRateLimitKey($route, $request);
        
        if ($this->isRateLimitExceeded($key, $limit)) {
            throw new RouteException('Route rate limit exceeded');
        }
    }

    private function validateProtectionConfig(array $config): void
    {
        $required = ['security_level', 'access_control', 'rate_limit'];
        
        foreach ($required as $field) {
            if (!isset($config[$field])) {
                throw new RouteException("Missing protection config: {$field}");
            }
        }
    }

    private function applyRouteProtection(string $route, array $protection): void
    {
        foreach ($protection['middleware'] as $middleware) {
            $this->validateMiddleware($middleware);
            $this->applyMiddleware($route, $middleware);
        }

        $this->applySecurityHeaders($route, $protection['headers']);
        $this->applyRateLimiting($route, $protection['rate_limit']);
    }

    private function cacheRouteProtection(string $route, array $protection): void
    {
        $this->cache->set(
            $this->getRouteCacheKey($route),
            $protection,
            $this->config['cache_ttl']
        );
    }

    private function isValidRoute(string $route): bool
    {
        return !empty($route) && 
               preg_match('/^[a-zA-Z0-9\/_-]+$/', $route);
    }

    private function isBlockedRoute(string $route): bool
    {
        return in_array($route, $this->config['blocked_routes']);
    }

    private function validateMethodAccess(string $route, string $method): bool
    {
        $allowedMethods = $this->getAllowedMethods($route);
        return in_array($method, $allowedMethods);
    }

    private function getRouteRequirements(string $route): array
    {
        return $this->config['route_requirements'][$route] ?? [];
    }

    private function getRouteRateLimit(string $route): int
    {
        return $this->config['rate_limits'][$route] ?? 
               $this->config['default_rate_limit'];
    }

    private function getRateLimitKey(string $route, Request $request): string
    {
        return sprintf(
            'route_limit:%s:%s:%s',
            $route,
            $request->ip(),
            $request->user()->id ?? 'guest'
        );
    }

    private function isRateLimitExceeded(string $key, int $limit): bool
    {
        $attempts = $this->cache->increment($key);
        
        if ($attempts === 1) {
            $this->cache->expire($key, 60);
        }
        
        return $attempts > $limit;
    }

    private function getDefaultProtection(): array
    {
        return [
            'security_level' => 'high',
            'access_control' => 'strict',
            'rate_limit' => $this->config['default_rate_limit'],
            'middleware' => $this->config['default_middleware'],
            'headers' => $this->config['security_headers']
        ];
    }

    private function validate