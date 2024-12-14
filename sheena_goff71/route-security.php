<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\{Route, Cache, Log};
use App\Core\Exceptions\SecurityException;

class RouteSecurityManager
{
    protected SecurityManager $security;
    protected array $protectedPaths = [
        'admin/*',
        'api/*',
        'cms/*'
    ];
    
    public function __construct(SecurityManager $security)
    {
        $this->security = $security;
    }

    public function protect(): void
    {
        foreach ($this->protectedPaths as $path) {
            Route::prefix($path)->middleware([
                'auth',
                'security.check',
                'throttle:60,1'
            ])->group(function() {
                $this->enforceSecurityProtocols();
            });
        }
    }

    public function enforceSecurityProtocols(): void
    {
        Route::middleware(function ($request, $next) {
            if (!$this->validateRequest($request)) {
                throw new SecurityException('Invalid request signature');
            }

            if ($this->isRateLimitExceeded($request)) {
                throw new SecurityException('Rate limit exceeded');
            }

            if (!$this->validateApiKey($request)) {
                throw new SecurityException('Invalid API credentials');
            }

            $response = $next($request);

            $this->validateResponse($response);
            $this->logApiAccess($request, $response);

            return $response;
        });
    }

    protected function validateRequest($request): bool
    {
        $signature = $request->header('X-Security-Signature');
        $timestamp = $request->header('X-Timestamp');
        
        if (!$signature || !$timestamp) {
            return false;
        }

        $payload = $request->getContent() . $timestamp;
        $expected = hash_hmac('sha256', $payload, config('security.api_secret'));
        
        return hash_equals($expected, $signature);
    }

    protected function isRateLimitExceeded($request): bool
    {
        $key = sprintf(
            'rate_limit:%s:%s',
            $request->ip(),
            $request->route()->getName()
        );

        $attempts = Cache::increment($key);
        Cache::expire($key, 60);
        
        return $attempts > config('security.rate_limit', 60);
    }

    protected function validateApiKey($request): bool
    {
        $key = $request->header('X-API-Key');
        
        if (!$key) {
            return false;
        }

        return Cache::tags(['api_keys'])->remember(
            "api_key:{$key}",
            300,
            fn() => $this->security->validateApiKey($key)
        );
    }

    protected function validateResponse($response): void
    {
        $data = $response->getContent();
        
        if (!$this->security->validateResponseIntegrity($data)) {
            throw new SecurityException('Response integrity check failed');
        }
    }

    protected function logApiAccess($request, $response): void
    {
        Log::channel('api')->info('API access', [
            'method' => $request->method(),
            'path' => $request->path(),
            'user' => auth()->id(),
            'ip' => $request->ip(),
            'status' => $response->status(),
            'duration' => microtime(true) - LARAVEL_START
        ]);
    }

    public function configureRateLimits(): void
    {
        Route::middleware('throttle:' . config('security.rate_limit'))
            ->prefix('api')
            ->group(base_path('routes/api.php'));
    }

    public function configureSecurityHeaders(): void
    {
        Route::middleware(function ($request, $next) {
            $response = $next($request);
            
            return $response->withHeaders([
                'X-Frame-Options' => 'DENY',
                'X-XSS-Protection' => '1; mode=block',
                'X-Content-Type-Options' => 'nosniff',
                'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
                'Content-Security-Policy' => $this->getContentSecurityPolicy(),
                'Referrer-Policy' => 'strict-origin-when-cross-origin'
            ]);
        });
    }

    protected function getContentSecurityPolicy(): string
    {
        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: https:",
            "font-src 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'"
        ]);
    }
}
