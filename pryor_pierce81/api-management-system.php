<?php

namespace App\Core\Api;

class ApiManager implements ApiManagerInterface 
{
    private SecurityManager $security;
    private ValidationService $validator;
    private RateLimiter $rateLimiter;
    private AuthManager $auth;
    private MetricsCollector $metrics;
    private CacheManager $cache;

    public function handleRequest(Request $request): Response 
    {
        $startTime = microtime(true);
        
        try {
            // Validate and authenticate request
            $this->validateRequest($request);
            $client = $this->authenticateRequest($request);
            
            // Check rate limits
            $this->checkRateLimits($client, $request);
            
            // Process request
            $response = $this->processRequest($client, $request);
            
            // Record metrics
            $this->recordMetrics($client, $request, $response, $startTime);
            
            return $response;

        } catch (ApiException $e) {
            return $this->handleApiError($e, $request);
        } catch (\Exception $e) {
            return $this->handleSystemError($e, $request);
        }
    }

    public function registerEndpoint(string $path, array $config): EndpointResult 
    {
        DB::beginTransaction();
        
        try {
            $this->security->validateCriticalOperation([
                'action' => 'api.register_endpoint',
                'path' => $path,
                'config' => $config
            ]);

            $validated = $this->validator->validate($config, [
                'method' => 'required|string',
                'handler' => 'required|string',
                'middleware' => 'array',
                'rate_limit' => 'integer',
                'cache_ttl' => 'integer',
                'documentation' => 'array'
            ]);

            // Validate handler
            $this->validateHandler($validated['handler']);

            $endpoint = $this->repository->create([
                'path' => $path,
                'method' => strtoupper($validated['method']),
                'handler' => $validated['handler'],
                'middleware' => $validated['middleware'] ?? [],
                'rate_limit' => $validated['rate_limit'] ?? null,
                'cache_ttl' => $validated['cache_ttl'] ?? null,
                'documentation' => $validated['documentation'] ?? [],
                'created_at' => now()
            ]);

            $this->cache->tags(['api'])->forget('endpoints');
            
            DB::commit();
            return new EndpointResult($endpoint);

        } catch (\Exception $e) {
            DB::rollBack();
            throw new ApiException('Failed to register endpoint', 0, $e);
        }
    }

    public function validateRequest(Request $request): void 
    {
        // Validate HTTP method
        if (!in_array($request->method(), ['GET', 'POST', 'PUT', 'DELETE'])) {
            throw new InvalidMethodException('Invalid HTTP method');
        }

        // Validate content type
        if ($request->method() !== 'GET' && !$request->isJson()) {
            throw new InvalidContentTypeException('Content type must be application/json');
        }

        // Validate request size
        if ($request->method() !== 'GET' && $request->getSize() > config('api.max_size')) {
            throw new RequestTooLargeException('Request body too large');
        }

        // Validate required headers
        foreach (['Accept', 'Authorization'] as $header) {
            if (!$request->hasHeader($header)) {
                throw new MissingHeaderException("Missing required header: {$header}");
            }
        }
    }

    private function authenticateRequest(Request $request): ApiClient 
    {
        try {
            $token = $this->auth->validateToken($request->bearerToken());
            return $this->auth->getClient($token->client_id);
        } catch (AuthException $e) {
            throw new UnauthorizedException('Invalid or expired token', 0, $e);
        }
    }

    private function checkRateLimits(ApiClient $client, Request $request): void 
    {
        $limit = $this->getRateLimit($client, $request->path());
        
        if ($limit && !$this->rateLimiter->check($client->id, $request->path(), $limit)) {
            throw new RateLimitExceededException('Rate limit exceeded');
        }
    }

    private function processRequest(ApiClient $client, Request $request): Response 
    {
        $endpoint = $this->findEndpoint($request->path(), $request->method());
        
        if (!$endpoint) {
            throw new EndpointNotFoundException('Endpoint not found');
        }

        // Check permissions
        if (!$this->auth->hasPermission($client, $endpoint->path)) {
            throw new UnauthorizedException('Insufficient permissions');
        }

        // Check if cached response exists
        if ($endpoint->cache_ttl > 0 && $request->method() === 'GET') {
            $cached = $this->getCachedResponse($client, $request);
            if ($cached) return $cached;
        }

        // Execute middleware
        foreach ($endpoint->middleware as $middleware) {
            $this->executeMiddleware($middleware, $request);
        }

        // Execute handler
        $response = $this->executeHandler($endpoint->handler, $request);
        
        // Cache response if needed
        if ($endpoint->cache_ttl > 0 && $request->method() === 'GET') {
            $this->cacheResponse($client, $request, $response, $endpoint->cache_ttl);
        }

        return $response;
    }

    private function findEndpoint(string $path, string $method): ?Endpoint 
    {
        return $this->cache->tags(['api'])->remember(
            "endpoint.{$path}.{$method}",
            3600,
            fn() => $this->repository->findEndpoint($path, $method)
        );
    }

    private function executeHandler(string $handler, Request $request): Response 
    {
        try {
            [$class, $method] = explode('@', $handler);
            return app($class)->$method($request);
        } catch (\Exception $e) {
            throw new HandlerExecutionException('Handler execution failed', 0, $e);
        }
    }

    private function executeMiddleware(string $middleware, Request $request): void 
    {
        try {
            app($middleware)->handle($request);
        } catch (\Exception $e) {
            throw new MiddlewareExecutionException('Middleware execution failed', 0, $e);
        }
    }

    private function getRateLimit(ApiClient $client, string $path): ?int 
    {
        $endpoint = $this->findEndpoint($path, 'GET');
        return $endpoint->rate_limit ?? $client->rate_limit ?? config('api.default_rate_limit');
    }

    private function recordMetrics(
        ApiClient $client, 
        Request $request, 
        Response $response,
        float $startTime
    ): void {
        $this->metrics->record([
            'client_id' => $client->id,
            'path' => $request->path(),
            'method' => $request->method(),
            'response_time' => microtime(true) - $startTime,
            'response_size' => strlen($response->getContent()),
            'status_code' => $response->status()
        ]);
    }

    private function getCachedResponse(ApiClient $client, Request $request): ?Response 
    {
        $key = $this->getCacheKey($client, $request);
        return $this->cache->tags(['api'])->get($key);
    }

    private function cacheResponse(
        ApiClient $client, 
        Request $request, 
        Response $response, 
        int $ttl
    ): void {
        $key = $this->getCacheKey($client, $request);
        $this->cache->tags(['api'])->put($key, $response, $ttl);
    }

    private function getCacheKey(ApiClient $client, Request $request): string 
    {
        return sprintf(
            'response.%s.%s.%s',
            $client->id,
            $request->path(),
            md5($request->getContent())
        );
    }
}
