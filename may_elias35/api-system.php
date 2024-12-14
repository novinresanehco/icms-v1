<?php

namespace App\Core\API;

use Illuminate\Support\Facades\{Cache, DB, Log};
use App\Core\Security\SecurityManager;
use App\Core\Exceptions\APIException;

class APIManager
{
    private SecurityManager $security;
    private RateLimiter $rateLimiter;
    private ResponseCache $cache;
    private APIValidator $validator;
    private AuditLogger $auditLogger;

    public function handleRequest(APIRequest $request): APIResponse
    {
        try {
            $this->validateRequest($request);
            $this->rateLimiter->checkLimit($request);
            
            if ($response = $this->getCachedResponse($request)) {
                return $response;
            }

            return $this->security->executeCriticalOperation(
                fn() => $this->processRequest($request),
                $request->getContext()
            );
            
        } catch (\Throwable $e) {
            return $this->handleRequestError($e, $request);
        }
    }

    private function processRequest(APIRequest $request): APIResponse
    {
        DB::beginTransaction();
        
        try {
            $result = $this->executeEndpoint($request);
            $response = new APIResponse($result);
            
            if ($request->isCacheable()) {
                $this->cacheResponse($request, $response);
            }
            
            DB::commit();
            $this->auditLogger->logAPIRequest($request, $response);
            
            return $response;
            
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function validateRequest(APIRequest $request): void
    {
        if (!$this->validator->validateRequest($request)) {
            throw new APIException('Invalid request format');
        }

        if (!$this->validator->validateAuthentication($request)) {
            throw new APIException('Authentication failed');
        }

        if (!$this->validator->validateAuthorization($request)) {
            throw new APIException('Unauthorized access');
        }
    }

    private function getCachedResponse(APIRequest $request): ?APIResponse
    {
        if (!$request->isCacheable()) {
            return null;
        }

        $cacheKey = $this->generateCacheKey($request);
        return $this->cache->get($cacheKey);
    }

    private function cacheResponse(APIRequest $request, APIResponse $response): void
    {
        $cacheKey = $this->generateCacheKey($request);
        $ttl = $this->calculateCacheTTL($request);
        
        $this->cache->put($cacheKey, $response, $ttl);
    }

    private function executeEndpoint(APIRequest $request): mixed
    {
        $endpoint = $this->resolveEndpoint($request);
        return $endpoint->handle($request);
    }

    private function handleRequestError(\Throwable $e, APIRequest $request): APIResponse
    {
        $this->auditLogger->logAPIError($e, $request);
        
        return new APIResponse(
            error: $this->formatError($e),
            status: $this->determineStatusCode($e)
        );
    }

    private function generateCacheKey(APIRequest $request): string
    {
        return sprintf(
            'api:%s:%s:%s',
            $request->getMethod(),
            $request->getPath(),
            md5(serialize($request->getParameters()))
        );
    }
}

class RateLimiter
{
    private Cache $cache;
    private array $limits;

    public function checkLimit(APIRequest $request): void
    {
        $key = $this->getLimitKey($request);
        $limit = $this->getLimit($request);
        
        $current = (int)$this->cache->increment($key);
        
        if ($current === 1) {
            $this->cache->expire($key, 3600);
        }
        
        if ($current > $limit) {
            throw new RateLimitException('Rate limit exceeded');
        }
    }

    private function getLimitKey(APIRequest $request): string
    {
        return sprintf(
            'rate_limit:%s:%s:%s',
            $request->getClientId(),
            $request->getEndpoint(),
            date('YmdH')
        );
    }

    private function getLimit(APIRequest $request): int
    {
        $endpoint = $request->getEndpoint();
        $clientType = $request->getClientType();
        
        return $this->limits[$endpoint][$clientType] ?? 
               $this->limits[$endpoint]['default'] ?? 
               1000;
    }
}

class ResponseCache
{
    private Cache $cache;
    private array $config;

    public function get(string $key): ?APIResponse
    {
        return $this->cache->get($key);
    }

    public function put(string $key, APIResponse $response, int $ttl): void
    {
        if ($response->isSuccessful() && !empty($response->getData())) {
            $this->cache->put($key, $response, $ttl);
        }
    }

    public function invalidate(array $tags): void
    {
        $this->cache->tags($tags)->flush();
    }
}
