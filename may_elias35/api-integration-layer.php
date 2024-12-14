namespace App\Core\Api;

use App\Core\Security\SecurityManager;
use App\Core\Cache\CacheManager;
use App\Core\Validation\ValidationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ApiManager implements ApiManagerInterface 
{
    private SecurityManager $security;
    private CacheManager $cache;
    private ValidationService $validator;
    private RateLimiter $limiter;
    private MetricsCollector $metrics;

    public function __construct(
        SecurityManager $security,
        CacheManager $cache,
        ValidationService $validator,
        RateLimiter $limiter,
        MetricsCollector $metrics
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->validator = $validator;
        $this->limiter = $limiter;
        $this->metrics = $metrics;
    }

    public function handle(Request $request): JsonResponse
    {
        $startTime = microtime(true);

        try {
            // Rate limiting check
            $this->limiter->check($request);

            // Validate request
            $this->validateRequest($request);

            // Process API request
            $response = $this->security->executeSecureOperation(
                fn() => $this->processRequest($request),
                ['action' => 'api.request']
            );

            // Track metrics
            $this->metrics->recordApiRequest(
                $request->path(),
                microtime(true) - $startTime
            );

            return $response;

        } catch (\Throwable $e) {
            return $this->handleError($e, $request);
        }
    }

    public function registerRoute(string $method, string $path, callable $handler): void
    {
        $this->security->executeSecureOperation(function() use ($method, $path, $handler) {
            $this->validateRoute($method, $path);
            $this->routes[$method][$path] = $handler;
            $this->clearRouteCache();
        }, ['action' => 'api.register']);
    }

    public function addMiddleware(string $path, callable $middleware): void
    {
        $this->security->executeSecureOperation(function() use ($path, $middleware) {
            $this->validateMiddleware($middleware);
            $this->middleware[$path][] = $middleware;
            $this->clearMiddlewareCache();
        }, ['action' => 'api.middleware']);
    }

    private function processRequest(Request $request): JsonResponse
    {
        // Get route handler
        $handler = $this->getRouteHandler($request);

        // Apply middleware
        $response = $this->applyMiddleware($request, function() use ($handler, $request) {
            return $handler($request);
        });

        // Cache if applicable
        if ($this->isCacheable($request)) {
            $this->cacheResponse($request, $response);
        }

        return $response;
    }

    private function validateRequest(Request $request): void
    {
        // Validate API version
        if (!$this->isValidVersion($request->header('Api-Version'))) {
            throw new ApiException('Invalid API version');
        }

        // Validate content type
        if (!$this->isValidContentType($request->header('Content-Type'))) {
            throw new ApiException('Invalid content type');
        }

        // Validate request data
        $rules = $this->getValidationRules($request->path());
        if (!$this->validator->validate($request->all(), $rules)) {
            throw new ValidationException('Invalid request data');
        }
    }

    private function getRouteHandler(Request $request): callable
    {
        $method = strtoupper($request->method());
        $path = $request->path();

        if (!isset($this->routes[$method][$path])) {
            throw new RouteNotFoundException('Route not found');
        }

        return $this->routes[$method][$path];
    }

    private function applyMiddleware(Request $request, callable $handler): JsonResponse
    {
        $middleware = $this->getMiddleware($request->path());

        $next = $handler;
        foreach (array_reverse($middleware) as $mw) {
            $next = fn() => $mw($request, $next);
        }

        return $next();
    }

    private function handleError(\Throwable $e, Request $request): JsonResponse
    {
        // Log error
        Log::error('API Error', [
            'exception' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'request' => [
                'path' => $request->path(),
                'method' => $request->method(),
                'ip' => $request->ip()
            ]
        ]);

        // Track error metrics
        $this->metrics->incrementApiError(
            get_class($e),
            $request->path()
        );

        // Return appropriate error response
        return match (true) {
            $e instanceof ValidationException => response()->json([
                'error' => 'Validation failed',
                'details' => $e->getMessage()
            ], 422),
            
            $e instanceof RouteNotFoundException => response()->json([
                'error' => 'Not found'
            ], 404),
            
            $e instanceof RateLimitException => response()->json([
                'error' => 'Too many requests'
            ], 429),
            
            $e instanceof AuthorizationException => response()->json([
                'error' => 'Unauthorized'
            ], 401),
            
            default => response()->json([
                'error' => 'Internal server error'
            ], 500)
        };
    }

    private function isCacheable(Request $request): bool
    {
        return $request->method() === 'GET' 
            && !$request->headers->has('Cache-Control');
    }

    private function cacheResponse(Request $request, JsonResponse $response): void
    {
        $key = $this->generateCacheKey($request);
        $ttl = $this->getCacheTTL($request->path());

        $this->cache->put($key, $response->getContent(), $ttl);
    }

    private function generateCacheKey(Request $request): string
    {
        return sprintf(
            'api:%s:%s:%s',
            $request->method(),
            $request->path(),
            md5(serialize($request->all()))
        );
    }

    private function isValidVersion(string $version): bool
    {
        return in_array($version, ['v1', 'v2']);
    }

    private function isValidContentType(?string $contentType): bool
    {
        return $contentType === 'application/json';
    }

    private function getValidationRules(string $path): array
    {
        return $this->cache->remember(
            "api:validation:$path",
            3600,
            fn() => $this->loadValidationRules($path)
        );
    }

    private function loadValidationRules(string $path): array
    {
        // Load from configuration or database
        return config("api.validation.$path", []);
    }

    private function getCacheTTL(string $path): int
    {
        return config("api.cache.$path", 3600);
    }

    private function clearRouteCache(): void
    {
        $this->cache->tags(['api:routes'])->flush();
    }

    private function clearMiddlewareCache(): void
    {
        $this->cache->tags(['api:middleware'])->flush();
    }
}

class RateLimiter
{
    private const LIMIT_KEY = 'api:limit:%s:%s';
    private const DEFAULT_LIMIT = 60;
    private const DEFAULT_WINDOW = 60;

    private Redis $redis;
    private array $config;

    public function check(Request $request): void
    {
        $key = $this->getLimitKey($request);
        $limit = $this->getLimit($request->path());
        $window = $this->getWindow($request->path());

        $current = $this->redis->incr($key);
        
        if ($current === 1) {
            $this->redis->expire($key, $window);
        }

        if ($current > $limit) {
            throw new RateLimitException();
        }
    }

    private function getLimitKey(Request $request): string
    {
        return sprintf(
            self::LIMIT_KEY,
            $request->ip(),
            $request->path()
        );
    }

    private function getLimit(string $path): int
    {
        return $this->config["limits.$path"] ?? self::DEFAULT_LIMIT;
    }

    private function getWindow(string $path): int
    {
        return $this->config["windows.$path"] ?? self::DEFAULT_WINDOW;
    }
}
