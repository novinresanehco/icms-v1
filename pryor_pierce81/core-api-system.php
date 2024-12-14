<?php

namespace App\Core\API;

use App\Core\Security\SecurityManager;
use App\Core\Cache\CacheManager;
use App\Core\Exceptions\{APIException, SecurityException};
use Illuminate\Support\Facades\{Cache, Log};

class APIManager implements APIManagerInterface 
{
    private SecurityManager $security;
    private CacheManager $cache;
    private RouterService $router;
    private ValidationService $validator;
    private RateLimiter $rateLimiter;
    private array $config;

    public function __construct(
        SecurityManager $security,
        CacheManager $cache,
        RouterService $router,
        ValidationService $validator,
        RateLimiter $rateLimiter,
        array $config
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->router = $router;
        $this->validator = $validator;
        $this->rateLimiter = $rateLimiter;
        $this->config = $config;
    }

    public function handleRequest(APIRequest $request): APIResponse 
    {
        $startTime = microtime(true);

        try {
            $this->validateRequest($request);
            $this->checkRateLimit($request);

            $route = $this->router->resolveRoute($request);
            $this->validatePermissions($request, $route);

            if ($response = $this->getCachedResponse($request, $route)) {
                return $response;
            }

            $response = $this->executeHandler($route, $request);
            $this->cacheResponse($request, $route, $response);

            return $response;

        } catch (\Exception $e) {
            return $this->handleError($e, $request);
        } finally {
            $this->logRequest($request, microtime(true) - $startTime);
        }
    }

    public function registerRoute(
        string $method,
        string $path,
        callable $handler,
        array $options = []
    ): void {
        $this->validateRoute($method, $path, $handler, $options);
        $this->router->addRoute($method, $path, $handler, $options);
    }

    public function registerMiddleware(string $path, callable $middleware): void 
    {
        $this->validateMiddleware($middleware);
        $this->router->addMiddleware($path, $middleware);
    }

    private function validateRequest(APIRequest $request): void 
    {
        if (!$this->validator->validateRequest($request)) {
            throw new ValidationException('Invalid API request');
        }

        $this->security->validateAccessToken($request->getToken());
    }

    private function checkRateLimit(APIRequest $request): void 
    {
        if (!$this->rateLimiter->checkLimit($request)) {
            throw new RateLimitException('Rate limit exceeded');
        }
    }

    private function validatePermissions(APIRequest $request, Route $route): void 
    {
        $permissions = $route->getRequiredPermissions();
        
        if (!$this->security->checkPermissions($request->getToken(), $permissions)) {
            throw new SecurityException('Insufficient permissions');
        }
    }

    private function getCachedResponse(APIRequest $request, Route $route): ?APIResponse 
    {
        if (!$route->isCacheable()) {
            return null;
        }

        return $this->cache->get($this->getCacheKey($request));
    }

    private function executeHandler(Route $route, APIRequest $request): APIResponse 
    {
        return $this->security->executeCriticalOperation(
            new APIOperation($route, $request),
            SecurityContext::fromRequest($request)
        );
    }

    private function cacheResponse(
        APIRequest $request,
        Route $route,
        APIResponse $response
    ): void {
        if (!$route->isCacheable() || !$response->isCacheable()) {
            return;
        }

        $this->cache->set(
            $this->getCacheKey($request),
            $response,
            $route->getCacheDuration()
        );
    }

    private function handleError(\Exception $e, APIRequest $request): APIResponse 
    {
        Log::error('API Error', [
            'request' => $request->toArray(),
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        return match (get_class($e)) {
            ValidationException::class => APIResponse::validation($e->getMessage()),
            SecurityException::class => APIResponse::unauthorized($e->getMessage()),
            RateLimitException::class => APIResponse::tooManyRequests($e->getMessage()),
            RouteNotFoundException::class => APIResponse::notFound($e->getMessage()),
            default => APIResponse::error($e->getMessage())
        };
    }

    private function logRequest(APIRequest $request, float $duration): void 
    {
        Log::info('API Request', [
            'method' => $request->getMethod(),
            'path' => $request->getPath(),
            'duration' => $duration,
            'status' => $request->getResponse()?->getStatus()
        ]);
    }

    private function getCacheKey(APIRequest $request): string 
    {
        return 'api.' . hash('sha256', serialize([
            $request->getMethod(),
            $request->getPath(),
            $request->getParameters()
        ]));
    }

    private function validateRoute(
        string $method,
        string $path,
        callable $handler,
        array $options
    ): void {
        if (!in_array($method, ['GET', 'POST', 'PUT', 'DELETE'])) {
            throw new APIException('Invalid HTTP method');
        }

        if (!preg_match('/^[a-zA-Z0-9\/_-]+$/', $path)) {
            throw new APIException('Invalid route path');
        }

        $this->validator->validateRouteOptions($options);
    }

    private function validateMiddleware(callable $middleware): void 
    {
        if (!$this->validator->validateMiddleware($middleware)) {
            throw new APIException('Invalid middleware');
        }
    }
}

class RateLimiter 
{
    private CacheManager $cache;
    private array $config;

    public function checkLimit(APIRequest $request): bool 
    {
        $key = $this->getLimitKey($request);
        $limit = $this->getLimit($request);
        
        $current = $this->cache->get($key, 0);
        
        if ($current >= $limit) {
            return false;
        }

        $this->cache->increment($key, 1, $this->config['window']);
        return true;
    }

    private function getLimitKey(APIRequest $request): string 
    {
        return 'rate_limit.' . hash('sha256', serialize([
            $request->getClientIP(),
            $request->getPath()
        ]));
    }

    private function getLimit(APIRequest $request): int 
    {
        foreach ($this->config['limits'] as $path => $limit) {
            if (preg_match($path, $request->getPath())) {
                return $limit;
            }
        }

        return $this->config['default_limit'];
    }
}

class APIResponse 
{
    private int $status;
    private array $data;
    private ?string $error;
    private bool $cacheable;

    public static function success(array $data): self 
    {
        return new self(200, $data);
    }

    public static function error(string $message): self 
    {
        return new self(500, [], $message);
    }

    public static function validation(string $message): self 
    {
        return new self(400, [], $message);
    }

    public static function unauthorized(string $message): self 
    {
        return new self(401, [], $message);
    }

    public static function notFound(string $message): self 
    {
        return new self(404, [], $message);
    }

    public static function tooManyRequests(string $message): self 
    {
        return new self(429, [], $message);
    }

    public function getStatus(): int 
    {
        return $this->status;
    }

    public function getData(): array 
    {
        return $this->data;
    }

    public function getError(): ?string 
    {
        return $this->error;
    }

    public function isCacheable(): bool 
    {
        return $this->cacheable && $this->status === 200;
    }

    public function toArray(): array 
    {
        return [
            'status' => $this->status,
            'data' => $this->data,
            'error' => $this->error
        ];
    }
}
