<?php

namespace App\Core\API;

use Illuminate\Support\Facades\{Cache, Log};
use App\Core\Security\SecurityContext;
use App\Core\Exceptions\ApiException;

class ApiManager
{
    private SecurityManager $security;
    private RequestValidator $validator;
    private ResponseFormatter $formatter;
    private RateLimiter $limiter;
    private CacheManager $cache;
    private array $config;

    public function __construct(
        SecurityManager $security,
        RequestValidator $validator,
        ResponseFormatter $formatter,
        RateLimiter $limiter,
        CacheManager $cache,
        array $config
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->formatter = $formatter;
        $this->limiter = $limiter;
        $this->cache = $cache;
        $this->config = $config;
    }

    public function handle(ApiRequest $request, SecurityContext $context): ApiResponse
    {
        return $this->security->executeCriticalOperation(function() use ($request) {
            // Rate limiting check
            $this->limiter->check($request);
            
            // Request validation
            $validatedData = $this->validator->validate($request);
            
            // Attempt to serve from cache
            if ($this->isCacheable($request)) {
                $cached = $this->getFromCache($request);
                if ($cached) {
                    return $this->formatter->format($cached);
                }
            }
            
            // Process request
            $result = $this->processRequest($request, $validatedData);
            
            // Cache if applicable
            if ($this->isCacheable($request)) {
                $this->cache->put(
                    $this->getCacheKey($request),
                    $result,
                    $this->getCacheTtl($request)
                );
            }
            
            return $this->formatter->format($result);
            
        }, $context);
    }

    private function processRequest(ApiRequest $request, array $validatedData): mixed
    {
        $handler = $this->resolveHandler($request);
        
        try {
            $startTime = microtime(true);
            $result = $handler->handle($validatedData);
            $this->logPerformance($request, microtime(true) - $startTime);
            
            return $result;
            
        } catch (\Exception $e) {
            $this->logError($request, $e);
            throw new ApiException('Request processing failed', 0, $e);
        }
    }

    private function resolveHandler(ApiRequest $request): RequestHandler
    {
        $handlerClass = $this->config['handlers'][$request->getEndpoint()] ?? null;
        
        if (!$handlerClass || !class_exists($handlerClass)) {
            throw new ApiException("No handler found for endpoint: {$request->getEndpoint()}");
        }
        
        return app($handlerClass);
    }

    private function isCacheable(ApiRequest $request): bool
    {
        return $request->getMethod() === 'GET' && 
               isset($this->config['cache_ttl'][$request->getEndpoint()]);
    }

    private function getCacheKey(ApiRequest $request): string
    {
        return "api:{$request->getEndpoint()}:" . md5(serialize($request->all()));
    }

    private function getCacheTtl(ApiRequest $request): int
    {
        return $this->config['cache_ttl'][$request->getEndpoint()] ?? 3600;
    }

    private function getFromCache(ApiRequest $request): mixed
    {
        return $this->cache->get($this->getCacheKey($request));
    }

    private function logPerformance(ApiRequest $request, float $duration): void
    {
        Log::info('API Request Performance', [
            'endpoint' => $request->getEndpoint(),
            'method' => $request->getMethod(),
            'duration' => $duration,
            'timestamp' => now()
        ]);
    }

    private function logError(ApiRequest $request, \Exception $e): void
    {
        Log::error('API Request Failed', [
            'endpoint' => $request->getEndpoint(),
            'method' => $request->getMethod(),
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'timestamp' => now()
        ]);
    }
}

class RequestValidator
{
    private array $globalRules = [
        'api_version' => 'required|string',
        'timestamp' => 'required|integer'
    ];

    private array $endpointRules;

    public function validate(ApiRequest $request): array
    {
        $rules = array_merge(
            $this->globalRules,
            $this->getEndpointRules($request)
        );
        
        $validator = validator($request->all(), $rules);
        
        if ($validator->fails()) {
            throw new ValidationException($validator->errors()->first());
        }
        
        return $validator->validated();
    }

    private function getEndpointRules(ApiRequest $request): array
    {
        return $this->endpointRules[$request->getEndpoint()] ?? [];
    }
}

class ResponseFormatter
{
    private array $config;

    public function format($data): ApiResponse
    {
        return new ApiResponse(
            success: true,
            data: $this->transformData($data),
            meta: $this->generateMeta()
        );
    }

    public function formatError(\Exception $e): ApiResponse
    {
        return new ApiResponse(
            success: false,
            error: [
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ],
            meta: $this->generateMeta()
        );
    }

    private function transformData($data): mixed
    {
        if (is_object($data) && method_exists($data, 'toArray')) {
            return $data->toArray();
        }
        return $data;
    }

    private function generateMeta(): array
    {
        return [
            'api_version' => $this->config['api_version'],
            'timestamp' => time(),
            'server_id' => $this->config['server_id']
        ];
    }
}

class RateLimiter
{
    private Cache $cache;
    private array $config;

    public function check(ApiRequest $request): void
    {
        $key = $this->getKey($request);
        $limit = $this->getLimit($request);
        
        $current = $this->cache->increment($key);
        
        if ($current === 1) {
            $this->cache->put($key, 1, 60);
        }
        
        if ($current > $limit) {
            throw new RateLimitException(
                "Rate limit exceeded for endpoint: {$request->getEndpoint()}"
            );
        }
    }

    private function getKey(ApiRequest $request): string
    {
        return "rate_limit:{$request->getEndpoint()}:{$request->getIp()}";
    }

    private function getLimit(ApiRequest $request): int
    {
        return $this->config['rate_limits'][$request->getEndpoint()] ?? 
               $this->config['default_rate_limit'];
    }
}

class ApiRequest
{
    private string $endpoint;
    private string $method;
    private array $data;
    private array $headers;
    private string $ip;

    public function getEndpoint(): string
    {
        return $this->endpoint;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function all(): array
    {
        return $this->data;
    }

    public function getIp(): string
    {
        return $this->ip;
    }
}

class ApiResponse
{
    public bool $success;
    public mixed $data;
    public ?array $error;
    public array $meta;

    public function __construct(
        bool $success,
        mixed $data = null,
        ?array $error = null,
        array $meta = []
    ) {
        $this->success = $success;
        $this->data = $data;
        $this->error = $error;
        $this->meta = $meta;
    }

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'data' => $this->data,
            'error' => $this->error,
            'meta' => $this->meta
        ];
    }
}
