namespace App\Core\Api;

use App\Core\Security\SecurityManager;
use App\Core\Services\ValidationService;
use App\Core\Services\MetricsCollector;
use App\Core\Cache\CacheManager;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ApiGateway
{
    private SecurityManager $security;
    private ValidationService $validator;
    private MetricsCollector $metrics;
    private CacheManager $cache;
    private array $config;
    private array $routes = [];

    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        MetricsCollector $metrics,
        CacheManager $cache,
        array $config
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->metrics = $metrics;
        $this->cache = $cache;
        $this->config = $config;
    }

    public function handleRequest(Request $request): Response
    {
        $startTime = microtime(true);
        $requestId = $this->generateRequestId();

        try {
            $this->validateRequest($request);
            $this->checkRateLimit($request);
            
            $route = $this->matchRoute($request);
            if (!$route) {
                throw new RouteNotFoundException();
            }

            $this->validateRouteAccess($route, $request);
            
            if ($this->canServeCached($route, $request)) {
                return $this->serveFromCache($route, $request);
            }

            $response = $this->processRequest($route, $request);
            
            $this->cacheResponse($route, $request, $response);
            $this->logRequest($requestId, $request, $response, microtime(true) - $startTime);
            
            return $response;

        } catch (\Throwable $e) {
            return $this->handleError($e, $requestId, $request);
        } finally {
            $this->metrics->recordApiRequest(
                $request->getPathInfo(),
                microtime(true) - $startTime
            );
        }
    }

    public function registerRoute(string $method, string $path, callable $handler, array $options = []): void
    {
        $this->validateRouteRegistration($method, $path, $options);
        
        $this->routes[] = [
            'method' => strtoupper($method),
            'path' => $path,
            'handler' => $handler,
            'options' => array_merge($this->getDefaultOptions(), $options)
        ];
    }

    protected function validateRequest(Request $request): void
    {
        if (!$this->validator->validateRequest($request)) {
            throw new ValidationException('Invalid request format');
        }

        if (!$this->validator->validateHeaders($request->headers->all())) {
            throw new ValidationException('Invalid request headers');
        }

        if (!empty($request->all())) {
            $this->validator->validateInput($request->all());
        }
    }

    protected function checkRateLimit(Request $request): void
    {
        $key = $this->getRateLimitKey($request);
        $limit = $this->config['rate_limit'];
        
        $current = $this->cache->increment("rate_limit:{$key}");
        
        if ($current > $limit) {
            throw new RateLimitExceededException();
        }
    }

    protected function matchRoute(Request $request): ?array
    {
        foreach ($this->routes as $route) {
            if ($this->routeMatches($route, $request)) {
                return $route;
            }
        }
        return null;
    }

    protected function validateRouteAccess(array $route, Request $request): void
    {
        $context = $this->buildSecurityContext($request);
        
        if (!$this->security->validateAccess($context)) {
            throw new UnauthorizedException();
        }

        if (isset($route['options']['permissions'])) {
            if (!$this->security->checkPermissions($context, $route['options']['permissions'])) {
                throw new ForbiddenException();
            }
        }
    }

    protected function processRequest(array $route, Request $request): Response
    {
        $data = $this->extractRequestData($request);
        $context = $this->buildRequestContext($request);
        
        $result = $this->security->executeSecureOperation(
            fn() => $route['handler']($data, $context),
            $context
        );

        return $this->buildResponse($result, $route['options']);
    }

    protected function canServeCached(array $route, Request $request): bool
    {
        return $request->isMethodSafe() &&
               isset($route['options']['cache']) &&
               !$this->isNoCacheRequest($request);
    }

    protected function serveFromCache(array $route, Request $request): Response
    {
        $cacheKey = $this->generateCacheKey($route, $request);
        
        if ($cached = $this->cache->get($cacheKey)) {
            $this->metrics->incrementCacheHit();
            return $this->buildResponse($cached, $route['options']);
        }

        $this->metrics->incrementCacheMiss();
        return $this->processRequest($route, $request);
    }

    protected function cacheResponse(array $route, Request $request, Response $response): void
    {
        if ($this->canServeCached($route, $request)) {
            $cacheKey = $this->generateCacheKey($route, $request);
            $ttl = $route['options']['cache']['ttl'] ?? 3600;
            
            $this->cache->put($cacheKey, $response->getContent(), $ttl);
        }
    }

    protected function buildResponse($data, array $options): Response
    {
        $response = new Response(
            $this->serializeResponse($data),
            200,
            ['Content-Type' => 'application/json']
        );

        if (isset($options['headers'])) {
            foreach ($options['headers'] as $key => $value) {
                $response->headers->set($key, $value);
            }
        }

        return $response;
    }

    protected function handleError(\Throwable $e, string $requestId, Request $request): Response
    {
        $this->logError($requestId, $e, $request);
        
        return new Response(
            $this->serializeError($e),
            $this->getErrorStatusCode($e),
            ['Content-Type' => 'application/json']
        );
    }

    protected function routeMatches(array $route, Request $request): bool
    {
        return $route['method'] === $request->getMethod() &&
               preg_match($this->buildRoutePattern($route['path']), $request->getPathInfo());
    }

    protected function buildRoutePattern(string $path): string
    {
        return '#^' . preg_replace('/\{([^}]+)\}/', '([^/]+)', $path) . '$#';
    }

    protected function generateRequestId(): string
    {
        return hash('sha256', uniqid('', true));
    }

    protected function generateCacheKey(array $route, Request $request): string
    {
        return 'api:' . hash('sha256', $route['path'] . $request->getQueryString());
    }

    protected function getRateLimitKey(Request $request): string
    {
        return hash('sha256', $request->ip() . $request->getPathInfo());
    }

    protected function isNoCacheRequest(Request $request): bool
    {
        return $request->headers->has('Cache-Control') &&
               stripos($request->headers->get('Cache-Control'), 'no-cache') !== false;
    }

    protected function getDefaultOptions(): array
    {
        return [
            'timeout' => 30,
            'retries' => 3,
            'rate_limit' => true
        ];
    }

    protected function buildSecurityContext(Request $request): array
    {
        return [
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'token' => $request->bearerToken(),
            'path' => $request->getPathInfo()
        ];
    }
}
