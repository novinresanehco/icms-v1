<?php

namespace App\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Core\Services\CategoryCacheService;
use Symfony\Component\HttpFoundation\Response;

class CategoryCacheMiddleware
{
    protected CategoryCacheService $cacheService;

    public function __construct(CategoryCacheService $cacheService)
    {
        $this->cacheService = $cacheService;
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->shouldBypassCache($request)) {
            return $next($request);
        }

        $cacheKey = $this->generateCacheKey($request);
        
        if ($cachedResponse = $this->getCachedResponse($cacheKey)) {
            return $cachedResponse;
        }

        $response = $next($request);

        if ($this->shouldCache($request, $response)) {
            $this->cacheResponse($cacheKey, $response);
        }

        return $response;
    }

    protected function shouldBypassCache(Request $request): bool
    {
        return $request->method() !== 'GET' ||
            $request->ajax() ||
            $request->expectsJson() ||
            auth()->check();
    }

    protected function generateCacheKey(Request $request): string
    {
        return 'category_page:' . sha1($request->fullUrl());
    }

    protected function getCachedResponse(string $key): ?Response
    {
        return Cache::tags(['categories'])->get($key);
    }

    protected function shouldCache(Request $request, Response $response): bool
    {
        return $response->isSuccessful() && 
            $response->getStatusCode() === Response::HTTP_OK;
    }

    protected function cacheResponse(string $key, Response $response): void
    {
        Cache::tags(['categories'])->put(
            $key,
            $response,
            config('cache.categories.ttl')
        );
    }
}
