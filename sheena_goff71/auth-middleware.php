<?php

namespace App\Core\Auth\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Core\Auth\Services\AuthService;
use App\Exceptions\AuthenticationException;

class TokenAuthentication
{
    public function __construct(private AuthService $authService)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken();

        if (!$token || !$this->authService->validateToken($token)) {
            throw new AuthenticationException('Invalid or expired token');
        }

        return $next($request);
    }
}

class RoleMiddleware
{
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        $user = $request->user();

        if (!$user) {
            throw new AuthenticationException('Unauthenticated');
        }

        foreach ($roles as $role) {
            if ($user->hasRole($role)) {
                return $next($request);
            }
        }

        throw new AuthenticationException('Insufficient role permissions');
    }
}

class PermissionMiddleware
{
    public function handle(Request $request, Closure $next, string ...$permissions)
    {
        $user = $request->user();

        if (!$user) {
            throw new AuthenticationException('Unauthenticated');
        }

        foreach ($permissions as $permission) {
            if ($user->hasPermission($permission)) {
                return $next($request);
            }
        }

        throw new AuthenticationException('Insufficient permissions');
    }
}
