<?php

namespace App\Core\Routes;

use App\Core\Security\SecurityManager;
use App\Core\Http\Middleware\CriticalSecurityMiddleware;
use Illuminate\Support\Facades\Route;

class CriticalRouteRegistrar
{
    private SecurityManager $security;
    private array $protectedRoutes;
    private array $publicRoutes;

    public function register(): void
    {
        $this->registerPublicRoutes();
        $this->registerProtectedRoutes();
        $this->registerApiRoutes();
        $this->registerAdminRoutes();
    }

    private function registerPublicRoutes(): void
    {
        Route::middleware(['web', CriticalSecurityMiddleware::class])
            ->group(function () {
                foreach ($this->publicRoutes as $route) {
                    $this->registerSecureRoute($route);
                }
            });
    }

    private function registerProtectedRoutes(): void
    {
        Route::middleware(['web', 'auth', CriticalSecurityMiddleware::class])
            ->group(function () {
                foreach ($this->protectedRoutes as $route) {
                    $this->registerSecureRoute($route);
                }
            });
    }

    private function registerApiRoutes(): void
    {
        Route::middleware(['api', 'auth:sanctum', CriticalSecurityMiddleware::class])
            ->prefix('api')
            ->group(function () {
                // Content Management
                Route::apiResource('contents', 'ContentController');
                Route::apiResource('media', 'MediaController');
                
                // User Management
                Route::apiResource('users', 'UserController');
                Route::apiResource('roles', 'RoleController');
            });
    }

    private function registerAdminRoutes(): void
    {
        Route::middleware(['web', 'auth', 'admin', CriticalSecurityMiddleware::class])
            ->prefix('admin')
            ->group(function () {
                // System Management
                Route::get('/dashboard', 'AdminController@dashboard');
                Route::get('/system/health', 'SystemController@health');
                Route::get('/system/logs', 'SystemController@logs');
                
                // Security Management
                Route::get('/security/audit', 'SecurityController@audit');
                Route::get('/security/alerts', 'SecurityController@alerts');
            });
    }

    private function registerSecureRoute(array $route): void
    {
        $method = strtolower($route['method']);
        
        Route::{$method}($route['uri'], [$route['controller'], $route['action']])
            ->name($route['name'])
            ->middleware($this->getRouteMiddleware($route));
    }

    private function getRouteMiddleware(array $route): array
    {
        $middleware = ['web', CriticalSecurityMiddleware::class];

        if ($route['auth'] ?? false) {
            $middleware[] = 'auth';
        }

        if ($route['admin'] ?? false) {
            $middleware[] = 'admin';
        }

        return $middleware;
    }
}
