<?php
namespace App\Http\Middleware;
use Illuminate\Support\Facades\{Cache, Hash, DB};

class CriticalAuthMiddleware
{
    private const MAX_ATTEMPTS = 3;
    private const LOCKOUT_TIME = 900;
    private const TOKEN_LIFETIME = 3600;

    public function handle($request, $next)
    {
        if (!$token = $request->header('X-Auth-Token')) {
            throw new AuthenticationException('No token provided');
        }

        $userId = $this->validateToken($token);
        if (!$userId) {
            throw new AuthenticationException('Invalid token');
        }

        if ($this->isBlocked($userId)) {
            throw new AuthenticationException('Account locked');
        }

        $user = $this->loadUser($userId);
        if (!$user || !$user->active) {
            throw new AuthenticationException('Invalid user');
        }

        $request->setUserResolver(fn() => $user);
        $response = $next($request);
        $this->logAccess($userId, $request);
        return $response;
    }

    private function validateToken($token): ?int
    {
        return Cache::get('auth:token:'.$token);
    }

    private function isBlocked($userId): bool
    {
        return Cache::get('auth:blocked:'.$userId, false);
    }

    private function loadUser($userId)
    {
        return Cache::remember(
            'user:'.$userId,
            300,
            fn() => DB::table('users')->find($userId)
        );
    }

    private function logAccess($userId, $request): void
    {
        DB::table('audit_logs')->insert([
            'user_id' => $userId,
            'action' => $request->method(),
            'path' => $request->path(),
            'ip' => $request->ip(),
            'created_at' => now()
        ]);
    }
}

class RoleMiddleware
{
    public function handle($request, $next, ...$roles)
    {
        $user = $request->user();
        
        if (!$user || !$this->hasRole($user, $roles)) {
            throw new AuthorizationException('Insufficient permissions');
        }

        return $next($request);
    }

    private function hasRole($user, array $roles): bool
    {
        return Cache::remember(
            'user:roles:'.$user->id,
            300,
            fn() => DB::table('role_user')
                ->where('user_id', $user->id)
                ->whereIn('role_id', function($query) use ($roles) {
                    $query->select('id')
                        ->from('roles')
                        ->whereIn('name', $roles);
                })
                ->exists()
        );
    }
}

class SecurityHeadersMiddleware
{
    public function handle($request, $next)
    {
        $response = $next($request);

        return $response->withHeaders([
            'X-Frame-Options' => 'DENY',
            'X-XSS-Protection' => '1; mode=block',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'self'",
            'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
            'Referrer-Policy' => 'strict-origin'
        ]);
    }
}

class ThrottleMiddleware
{
    private const MAX_ATTEMPTS = 60;
    private const DECAY_MINUTES = 1;

    public function handle($request, $next)
    {
        $key = $this->resolveRequestSignature($request);
        
        if ($this->exceedsAttempts($key)) {
            throw new ThrottleException('Too many requests');
        }

        $response = $next($request);
        $this->incrementAttempts($key);
        return $response;
    }

    private function resolveRequestSignature($request): string
    {
        return Hash::make(
            $request->ip().
            $request->path().
            $request->header('User-Agent')
        );
    }

    private function exceedsAttempts($key): bool
    {
        return Cache::get($key, 0) >= self::MAX_ATTEMPTS;
    }

    private function incrementAttempts($key): void
    {
        Cache::add($key, 0, self::DECAY_MINUTES * 60);
        Cache::increment($key);
    }
}
