<?php

namespace App\Http\Middleware;

class AuthenticateRequests
{
    private SecurityManager $security;
    private AuditLogger $audit;

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $result = $this->security->validateRequest($request);
            
            if (!$result->isValid()) {
                throw new AuthenticationException('Invalid authentication');
            }

            return $next($request);

        } catch (AuthenticationException $e) {
            $this->audit->logAuthFailure($request);
            throw $e;
        }
    }
}

class ValidatePermissions
{
    private AuthorizationManager $authz;
    private AuditLogger $audit;

    public function handle(Request $request, Closure $next): Response
    {
        $permission = $request->route()->getPermission();
        
        if ($permission && !$this->authz->authorize($request->user(), $permission)) {
            $this->audit->logUnauthorizedAccess($request->user(), $permission);
            throw new AuthorizationException('Permission denied');
        }

        return $next($request);
    }
}

class ValidateSecureRequests
{
    private ValidationService $validator;
    private SecurityManager $security;
    private AuditLogger $audit;

    public function handle(Request $request, Closure $next): Response
    {
        $this->validateHttps($request);
        $this->validateCsrf($request);
        $this->validateHeaders($request);
        $this->validateInput($request);

        return $next($request);
    }

    private function validateHttps(Request $request): void
    {
        if (!$request->secure() && config('app.env') === 'production') {
            throw new SecurityException('HTTPS required');
        }
    }

    private function validateCsrf(Request $request): void
    {
        if (!$this->security->verifyCsrfToken($request)) {
            throw new SecurityException('Invalid CSRF token');
        }
    }

    private function validateHeaders(Request $request): void
    {
        $rules = [
            'Content-Type' => ['required', 'in:application/json'],
            'Accept' => ['required', 'in:application/json'],
            'User-Agent' => ['required', 'string', 'max:255']
        ];

        $this->validator->validate($request->headers->all(), $rules);
    }

    private function validateInput(Request $request): void
    {
        if ($request->isJson()) {
            if (!$this->isValidJson($request->getContent())) {
                throw new ValidationException('Invalid JSON payload');
            }
        }
    }

    private function isValidJson(string $content): bool
    {
        json_decode($content);
        return json_last_error() === JSON_ERROR_NONE;
    }
}

class RateLimiter
{
    private CacheManager $cache;
    private SecurityManager $security;
    private AuditLogger $audit;

    public function handle(Request $request, Closure $next): Response
    {
        $key = $this->getRateLimitKey($request);
        $limit = $this->getLimit($request);
        
        if ($this->hitRateLimit($key, $limit)) {
            $this->audit->logRateLimitExceeded($request);
            throw new TooManyRequestsException();
        }

        return $next($request);
    }

    private function getRateLimitKey(Request $request): string
    {
        return 'rate_limit:' . $this->security->generateKey([
            $request->ip(),
            $request->path(),
            $request->user()?->id
        ]);
    }

    private function getLimit(Request $request): array
    {
        $defaults = config('security.rate_limits.default');

        if ($path = $this->matchPathLimit($request->path())) {
            return array_merge($defaults, $path);
        }

        return $defaults;
    }

    private function hitRateLimit(string $key, array $limit): bool
    {
        $attempts = (int)$this->cache->increment($key);
        
        if ($attempts === 1) {
            $this->cache->expire($key, $limit['interval']);
        }

        return $attempts > $limit['max_attempts'];
    }

    private function matchPathLimit(string $path): ?array
    {
        $limits = config('security.rate_limits.paths', []);

        foreach ($limits as $pattern => $limit) {
            if (preg_match($pattern, $path)) {
                return $limit;
            }
        }

        return null;
    }
}

class LogRequests
{
    private AuditLogger $audit;
    private MetricsCollector $metrics;

    public function handle(Request $request, Closure $next): Response
    {
        $startTime = microtime(true);
        
        $response = $next($request);

        $duration = microtime(true) - $startTime;
        
        $this->audit->logRequest($request, $response, [
            'duration' => $duration,
            'memory' => memory_get_peak_usage(true)
        ]);

        $this->metrics->measure('request.duration', $duration);
        $this->metrics->increment('request.count');

        return $response;
    }
}
