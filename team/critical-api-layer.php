<?php

namespace App\Core\API;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Cache\CacheManager;
use App\Core\Validation\ValidationService;
use Illuminate\Support\Facades\{DB, Log};

class APIManager implements APIManagerInterface
{
    private SecurityManagerInterface $security;
    private ValidationService $validator;
    private CacheManager $cache;
    private RateLimiter $limiter;
    private MonitoringService $monitor;

    public function __construct(
        SecurityManagerInterface $security,
        ValidationService $validator,
        CacheManager $cache,
        RateLimiter $limiter,
        MonitoringService $monitor
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->cache = $cache;
        $this->limiter = $limiter;
        $this->monitor = $monitor;
    }

    public function handleRequest(APIRequest $request): APIResponse
    {
        $operationId = $this->monitor->startOperation('api_request');

        try {
            $this->validateRequest($request);
            $this->checkRateLimit($request);

            return $this->security->executeCriticalOperation(
                fn() => $this->processRequest($request),
                new SecurityContext('api.request', ['request' => $request])
            );

        } catch (\Throwable $e) {
            $this->handleError($e, $request);
            throw $e;
        } finally {
            $this->monitor->stopOperation($operationId);
        }
    }

    public function registerEndpoint(string $path, string $method, array $config): void
    {
        $this->security->executeCriticalOperation(
            function() use ($path, $method, $config) {
                $this->validateEndpointConfig($config);
                $this->storeEndpointConfig($path, $method, $config);
                $this->cache->forget("api_endpoints");
            },
            new SecurityContext('api.register_endpoint')
        );
    }

    protected function processRequest(APIRequest $request): APIResponse
    {
        $span = $this->monitor->startSpan('request_processing');

        try {
            if ($cached = $this->getCachedResponse($request)) {
                return $cached;
            }

            DB::beginTransaction();

            $endpoint = $this->resolveEndpoint($request);
            $this->validatePermissions($request, $endpoint);
            
            $result = $this->executeEndpoint($endpoint, $request);
            $response = $this->formatResponse($result);

            $this->cacheResponse($request, $response);
            
            DB::commit();
            return $response;

        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        } finally {
            $this->monitor->endSpan($span);
        }
    }

    protected function validateRequest(APIRequest $request): void
    {
        if (!$this->validator->validateAPIRequest($request)) {
            throw new APIValidationException('Invalid API request');
        }

        if (!$this->security->validateToken($request->getToken())) {
            throw new APIAuthenticationException('Invalid API token');
        }

        $this->validateRequestSignature($request);
    }

    protected function checkRateLimit(APIRequest $request): void
    {
        if (!$this->limiter->checkLimit($request)) {
            throw new APIRateLimitException(
                'Rate limit exceeded',
                $this->limiter->getRemainingTime($request)
            );
        }
    }

    protected function validateRequestSignature(APIRequest $request): void
    {
        $signature = $request->getSignature();
        $computed = $this->security->computeSignature(
            $request->getMethod(),
            $request->getPath(),
            $request->getParameters(),
            $request->getTimestamp()
        );

        if (!hash_equals($signature, $computed)) {
            throw new APISecurityException('Invalid request signature');
        }
    }

    protected function resolveEndpoint(APIRequest $request): APIEndpoint
    {
        $endpoints = $this->cache->remember(
            'api_endpoints',
            fn() => $this->loadEndpoints()
        );

        $key = $request->getMethod() . ':' . $request->getPath();
        
        if (!isset($endpoints[$key])) {
            throw new APINotFoundException('Endpoint not found');
        }

        return $endpoints[$key];
    }

    protected function validatePermissions(APIRequest $request, APIEndpoint $endpoint): void
    {
        if (!$this->security->checkPermissions($request->getToken(), $endpoint->getRequiredPermissions())) {
            throw new APIForbiddenException('Insufficient permissions');
        }
    }

    protected function executeEndpoint(APIEndpoint $endpoint, APIRequest $request): mixed
    {
        $span = $this->monitor->startSpan('endpoint_execution');

        try {
            return $endpoint->execute($request);
        } finally {
            $this->monitor->endSpan($span);
        }
    }

    protected function getCachedResponse(APIRequest $request): ?APIResponse
    {
        if (!$request->isCacheable()) {
            return null;
        }

        return $this->cache->get($this->getCacheKey($request));
    }

    protected function cacheResponse(APIRequest $request, APIResponse $response): void
    {
        if ($request->isCacheable() && $response->isCacheable()) {
            $this->cache->put(
                $this->getCacheKey($request),
                $response,
                $request->getCacheTTL()
            );
        }
    }

    protected function getCacheKey(APIRequest $request): string
    {
        return 'api:' . hash('sha256', serialize([
            $request->getMethod(),
            $request->getPath(),
            $request->getParameters()
        ]));
    }

    protected function handleError(\Throwable $e, APIRequest $request): void
    {
        $this->monitor->recordError('api_error', [
            'error' => $e->getMessage(),
            'request' => $request,
            'trace' => $e->getTraceAsString()
        ]);

        if ($e instanceof SecurityException) {
            $this->security->handleSecurityViolation(
                new SecurityContext('api.security_violation', ['request' => $request])
            );
        }
    }
}
