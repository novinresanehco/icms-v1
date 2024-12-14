<?php
namespace App\Core\Security;

class SecurityMiddleware
{
    protected TokenValidator $tokens;
    protected AccessControl $access;
    protected Logger $logger;

    public function handle(Request $request, Closure $next)
    {
        try {
            $token = $request->bearerToken();
            if (!$user = $this->tokens->validate($token)) {
                throw new AuthException('Invalid token');
            }
            
            if (!$this->access->check($user, $request->route())) {
                throw new AuthException('Unauthorized');
            }

            $request->setUser($user);
            return $next($request);
            
        } catch (\Exception $e) {
            $this->logger->warning('Security violation', [
                'ip' => $request->ip(),
                'path' => $request->path(),
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
}

class ValidationMiddleware
{
    protected array $rules = [
        'store' => [
            'title' => 'required|string|max:255',
            'body' => 'required|string',
            'status' => 'required|in:draft,published',
            'published_at' => 'nullable|date'
        ],
        'update' => [
            'title' => 'string|max:255',
            'body' => 'string',
            'status' => 'in:draft,published',
            'published_at' => 'nullable|date'
        ]
    ];

    public function handle(Request $request, Closure $next)
    {
        $action = $request->route()->getActionMethod();
        
        if (isset($this->rules[$action])) {
            $validated = $request->validate($this->rules[$action]);
            $request->replace($validated);
        }

        return $next($request);
    }
}

class RateLimitMiddleware
{
    protected RateLimiter $limiter;
    protected int $maxAttempts = 60;
    protected int $decayMinutes = 1;

    public function handle(Request $request, Closure $next)
    {
        $key = $request->ip();

        if ($this->limiter->tooManyAttempts($key, $this->maxAttempts)) {
            throw new RateLimitException('Too many requests');
        }

        $this->limiter->hit($key, $this->decayMinutes * 60);

        $response = $next($request);

        return $response->withHeaders([
            'X-RateLimit-Limit' => $this->maxAttempts,
            'X-RateLimit-Remaining' => $this->limiter->remaining($key, $this->maxAttempts),
        ]);
    }
}

class SanitizationMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $input = $request->all();
        array_walk_recursive($input, function(&$value) {
            $value = is_string($value) ? strip_tags($value) : $value;
        });
        $request->merge($input);
        
        return $next($request);
    }
}

class ApiExceptionHandler extends ExceptionHandler
{
    protected function shouldReport(\Throwable $e): bool
    {
        return !$e instanceof ValidationException;
    }

    public function render($request, \Throwable $e)
    {
        if ($e instanceof AuthException) {
            return response()->json([
                'error' => 'Unauthorized',
                'message' => $e->getMessage()
            ], 401);
        }

        if ($e instanceof ValidationException) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        }

        if ($e instanceof RateLimitException) {
            return response()->json([
                'error' => 'Too many requests',
                'message' => $e->getMessage()
            ], 429);
        }

        return response()->json([
            'error' => 'Server error',
            'message' => 'An unexpected error occurred'
        ], 500);
    }
}

class Kernel extends HttpKernel
{
    protected $middleware = [
        TrustProxies::class,
        SanitizationMiddleware::class,
        RateLimitMiddleware::class
    ];

    protected $middlewareGroups = [
        'api' => [
            SecurityMiddleware::class,
            ValidationMiddleware::class,
            'throttle:60,1'
        ]
    ];
}
