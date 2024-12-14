<?php

namespace App\Services;

use App\Interfaces\SecurityServiceInterface;
use Illuminate\Http\{Request, Response};
use Illuminate\Support\Facades\{Cache, Redis, Validator};
use App\Exceptions\{ApiException, ValidationException};

class ApiService
{
    private SecurityServiceInterface $security;
    private CacheService $cache;
    private array $rateLimits = [
        'default' => [
            'attempts' => 60,
            'decay' => 60
        ],
        'auth' => [
            'attempts' => 5,
            'decay' => 300
        ]
    ];

    public function __construct(
        SecurityServiceInterface $security,
        CacheService $cache
    ) {
        $this->security = $security;
        $this->cache = $cache;
    }

    public function processRequest(Request $request, array $config): Response
    {
        return $this->security->validateSecureOperation(
            fn() => $this->executeRequest($request, $config),
            ['action' => 'api.request']
        );
    }

    private function executeRequest(Request $request, array $config): Response
    {
        try {
            $this->validateRequest($request, $config);
            $this->checkRateLimit($request, $config['rate_limit'] ?? 'default');

            $cacheKey = $this->generateCacheKey($request);
            
            if ($this->shouldCache($config) && $this->cache->has($cacheKey)) {
                return $this->cache->get($cacheKey);
            }

            $response = $this->handleRequest($request, $config);

            if ($this->shouldCache($config)) {
                $this->cacheResponse($cacheKey, $response, $config['cache_ttl'] ?? 3600);
            }

            return $response;

        } catch (ValidationException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        } catch (ApiException $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode());
        } catch (\Throwable $e) {
            $this->logError($e, $request);
            return $this->errorResponse('Internal Server Error', 500);
        }
    }

    private function validateRequest(Request $request, array $config): void
    {
        if (!isset($config['validation'])) {
            return;
        }

        $validator = Validator::make(
            $request->all(), 
            $config['validation']
        );

        if ($validator->fails()) {
            throw new ValidationException($validator->errors()->first());
        }
    }

    private function checkRateLimit(Request $request, string $type): void
    {
        if (!isset($this->rateLimits[$type])) {
            $type = 'default';
        }

        $key = $this->getRateLimitKey($request, $type);
        $attempts = $this->rateLimits[$type]['attempts'];
        $decay = $this->rateLimits[$type]['decay'];

        $current = Redis::get($key) ?? 0;
        
        if ($current >= $attempts) {
            throw new ApiException('Too Many Requests', 429);
        }

        Redis::incr($key);
        Redis::expire($key, $decay);

        header('X-RateLimit-Limit: ' . $attempts);
        header('X-RateLimit-Remaining: ' . ($attempts - $current - 1));
    }

    private function handleRequest(Request $request, array $config): Response
    {
        if (!isset($config['handler']) || !is_callable($config['handler'])) {
            throw new ApiException('Invalid request handler', 500);
        }

        $result = call_user_func($config['handler'], $request);
        return $this->successResponse($result);
    }

    private function successResponse($data, int $status = 200): Response
    {
        return response()->json([
            'status' => 'success',
            'data' => $data
        ], $status);
    }

    private function errorResponse(string $message, int $status): Response
    {
        return response()->json([
            'status' => 'error',
            'message' => $message
        ], $status);
    }

    private function shouldCache(array $config): bool
    {
        return isset($config['cache']) && $config['cache'] === true;
    }

    private function generateCacheKey(Request $request): string
    {
        return 'api:' . md5(
            $request->method() . 
            $request->path() . 
            serialize($request->all())
        );
    }

    private function getRateLimitKey(Request $request, string $type): string
    {
        $identifier = $request->user() ? 
            $request->user()->id : 
            $request->ip();

        return "ratelimit:{$type}:{$identifier}";
    }

    private function cacheResponse(string $key, Response $response, int $ttl): void
    {
        $this->cache->remember($key, $response, $ttl);
    }

    private function logError(\Throwable $e, Request $request): void
    {
        \Log::error('API Error', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'method' => $request->method(),
            'path' => $request->path(),
            'ip' => $request->ip(),
            'user' => $request->user() ? $request->user()->id : null
        ]);
    }

    public function registerEndpoint(
        string $method,
        string $path,
        callable $handler,
        array $options = []
    ): void {
        $this->security->validateSecureOperation(
            fn() => $this->executeRegisterEndpoint($method, $path, $handler, $options),
            ['action' => 'api.register', 'permission' => 'api.manage']
        );
    }

    private function executeRegisterEndpoint(
        string $method,
        string $path,
        callable $handler,
        array $options
    ): void {
        $config = array_merge([
            'method' => strtoupper($method),
            'path' => $path,
            'handler' => $handler,
            'validation' => [],
            'cache' => false,
            'cache_ttl' => 3600,
            'rate_limit' => 'default'
        ], $options);

        $key = "api:endpoints:{$method}:{$path}";
        Cache::forever($key, $config);
    }

    public function getEndpointConfig(string $method, string $path): ?array
    {
        $key = "api:endpoints:{$method}:{$path}";
        return Cache::get($key);
    }
}
