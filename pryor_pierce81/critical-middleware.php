<?php
namespace CriticalCms\Middleware;

class SecurityKernel
{
    protected array $middleware = [
        GlobalSecurityMiddleware::class,
        ApiAuthMiddleware::class,
        RateLimitMiddleware::class,
        ContentValidationMiddleware::class,
        XssProtectionMiddleware::class,
    ];

    protected array $middlewareGroups = [
        'api' => [
            RequestLogMiddleware::class,
            JsonResponseMiddleware::class,
        ],
    ];
}

class GlobalSecurityMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        if (!$this->validateRequest($request)) {
            throw new SecurityException('Invalid request signature');
        }

        $response = $next($request);
        return $this->secureResponse($response);
    }

    protected function validateRequest(Request $request): bool
    {
        return $request->hasValidSignature() && 
               !$this->isBlocked($request->ip()) &&
               $this->validateCsrf($request);
    }

    protected function secureResponse($response): Response
    {
        return $response->withHeaders([
            'X-Frame-Options' => 'DENY',
            'X-XSS-Protection' => '1; mode=block',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'self'",
            'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains'
        ]);
    }
}

class ApiAuthMiddleware
{
    protected AuthManager $auth;
    protected TokenService $tokens;

    public function handle(Request $request, Closure $next)
    {
        if (!$token = $request->bearerToken()) {
            throw new AuthException('No token provided');
        }

        if (!$user = $this->tokens->validate($token)) {
            throw new AuthException('Invalid token');
        }

        if (!$this->auth->validateAccess($user, $request->route())) {
            throw new AuthException('Unauthorized access');
        }

        $request->setUserResolver(fn() => $user);
        return $next($request);
    }
}

class RateLimitMiddleware
{
    protected RateLimiter $limiter;
    protected array $limits = [
        'api' => [60, 1],      // 60 requests per minute
        'auth' => [5, 1],      // 5 attempts per minute
        'content' => [300, 1]  // 300 requests per minute
    ];

    public function handle(Request $request, Closure $next)
    {
        [$maxAttempts, $minutes] = $this->getLimits($request);
        $key = $this->resolveRequestSignature($request);

        if ($this->limiter->tooManyAttempts($key, $maxAttempts)) {
            throw new RateLimitException;
        }

        $this->limiter->hit($key, $minutes * 60);

        $response = $next($request);

        return $response->withHeaders([
            'X-RateLimit-Limit' => $maxAttempts,
            'X-RateLimit-Remaining' => $this->limiter->remaining($key, $maxAttempts)
        ]);
    }

    protected function resolveRequestSignature(Request $request): string
    {
        return sha1(implode('|', [
            $request->ip(),
            $request->route()->getName(),
            $request->user()?->id
        ]));
    }
}

class ContentValidationMiddleware
{
    protected array $rules = [
        'store' => [
            'title' => 'required|string|max:255',
            'body' => 'required|string',
            'status' => 'required|in:draft,published',
            'metadata' => 'array'
        ],
        'update' => [
            'title' => 'string|max:255',
            'body' => 'string',
            'status' => 'in:draft,published',
            'metadata' => 'array'
        ]
    ];

    public function handle(Request $request, Closure $next)
    {
        $action = $request->route()->getActionMethod();
        
        if (isset($this->rules[$action])) {
            $validated = $request->validate($this->rules[$action]);
            $request->replace($validated);
        }

        $response = $next($request);
        Cache::tags('content')->flush();
        
        return $response;
    }
}

class XssProtectionMiddleware
{
    protected HtmlPurifier $purifier;

    public function handle(Request $request, Closure $next)
    {
        $input = $request->all();
        array_walk_recursive($input, function(&$value) {
            if (is_string($value)) {
                $value = $this->purifier->purify($value);
            }
        });

        $request->merge($input);
        return $next($request);
    }
}
