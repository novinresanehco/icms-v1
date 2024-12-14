<?php

namespace App\Core\RateLimit\Middleware;

use App\Core\RateLimit\Services\RateLimitService;
use Closure;
use Symfony\Component\HttpFoundation\Response;

class RateLimit
{
    public function __construct(private RateLimitService $rateLimitService) 
    {}

    public function handle($request, Closure $next, $maxAttempts = 60, $decayMinutes = 1)
    {
        $key = $this->resolveRequestSignature($request);

        if (!$this->rateLimitService->attempt($key, [
            'limit' => $maxAttempts,
            'ttl' => $decayMinutes * 60
        ])) {
            return $this->buildResponse($key, $maxAttempts);
        }

        $response = $next($request);

        return $this->addHeaders(
            $response, 
            $maxAttempts,
            $this->rateLimitService->getRemainingAttempts($key, $maxAttempts)
        );
    }

    protected function resolveRequestSignature($request): string
    {
        return sha1(implode('|', [
            $request->method(),
            $request->ip(),
            $request->path()
        ]));
    }

    protected function buildResponse(string $key, int $maxAttempts): Response
    {
        return response()->json([
            'error' => 'Too Many Attempts.',
            'retryAfter' => $this->getRetryAfter()
        ], 429)->withHeaders([
            'X-RateLimit-Limit' => $maxAttempts,
            'X-RateLimit-Remaining' => 0,
            'Retry-After' => $this->getRetryAfter()
        ]);
    }

    protected function addHeaders(Response $response, int $maxAttempts, int $remainingAttempts): Response
    {
        return $response->withHeaders([
            'X-RateLimit-Limit' => $maxAttempts,
            'X-RateLimit-Remaining' => $remainingAttempts,
        ]);
    }

    protected function getRetryAfter(): int
    {
        return config('rate-limit.retry_after', 60);
    }
}
