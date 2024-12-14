<?php

namespace App\Core\Api\Middleware;

class SecurityMiddleware
{
    private $security;
    private $monitor;

    public function handle(Request $request, \Closure $next)
    {
        $startTime = microtime(true);

        try {
            // Security checks
            $this->security->validateToken($request);
            $this->security->validatePermissions($request);
            $this->security->validateInput($request);

            // Process request
            $response = $next($request);

            // Performance monitoring
            $this->monitor->trackRequestMetrics([
                'duration' => microtime(true) - $startTime,
                'endpoint' => $request->path(),
                'method' => $request->method()
            ]);

            return $response;

        } catch (\Exception $e) {
            $this->monitor->logFailure($e);
            throw $e;
        }
    }
}

class RateLimitMiddleware 
{
    private $limiter;
    private $monitor;

    public function handle(Request $request, \Closure $next)
    {
        $key = $this->resolveRequestSignature($request);

        if (!$this->limiter->attempt($key)) {
            $this->monitor->logRateLimitExceeded($request);
            throw new RateLimitExceededException();
        }

        return $next($request);
    }

    private function resolveRequestSignature(Request $request): string
    {
        return sha1(implode('|', [
            $request->ip(),
            $request->path(),
            $request->user()?->id
        ]));
    }
}

class ValidationMiddleware
{
    private $validator;
    private $monitor;

    public function handle(Request $request, \Closure $next)
    {
        try {
            $this->validator->validateRequest($request);
            return $next($request);
        } catch (ValidationException $e) {
            $this->monitor->logValidationFailure($e);
            throw $e;
        }
    }
}
