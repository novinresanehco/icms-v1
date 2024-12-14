<?php

namespace App\Core\Routing;

use App\Core\Security\{SecurityContext, CoreSecurityManager};
use App\Core\Cache\CachePerformanceManager;
use Illuminate\Support\Facades\{Route, Cache};
use Symfony\Component\HttpFoundation\{Request, Response};

class CoreRoutingManager implements RoutingInterface
{
    private CoreSecurityManager $security;
    private CachePerformanceManager $cache;
    private ValidationService $validator;
    private RateLimiter $rateLimiter;
    private MetricsCollector $metrics;
    private array $config;

    public function __construct(
        CoreSecurityManager $security,
        CachePerformanceManager $cache,
        ValidationService $validator,
        RateLimiter $rateLimiter,
        MetricsCollector $metrics,
        array $config
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->validator = $validator;
        $this->rateLimiter = $rateLimiter;
        $this->metrics = $metrics;
        $this->config = $config;
    }

    public function registerRoutes(): void
    {
        $this->security->executeCriticalOperation(
            new RoutingOperation('register_routes'),
            new SecurityContext(['system' => true]),
            function() {
                $this->registerApiRoutes();
                $this->registerWebRoutes();
                $this->registerAdminRoutes();
                $this->registerAssetRoutes();
                $this->cache->invalidateTag('routes');
            }
        );
    }

    public function processRequest(Request $request): Response
    {
        $startTime = microtime(true);
        $context = $this->createSecurityContext($request);

        try {
            $this->validateRequest($request, $context);
            $this->checkRateLimits($request, $context);
            
            $response = $this->handleRequest($request, $context);
            
            $this->logRequestMetrics($request, $response, $startTime);
            return $response;

        } catch (\Exception $e) {
            return $this->handleRequestError($e, $context);
        }
    }

    private function registerApiRoutes(): void
    {
        Route::middleware(['api', 'auth:api'])
            ->prefix('api/v1')
            ->group(function() {
                // Content Routes
                Route::apiResource('content', 'ContentController');
                Route::post('content/{id}/publish', 'ContentController@publish');
                Route::post('content/{id}/unpublish', 'ContentController@unpublish');
                Route::get('content/{id}/versions', 'ContentController@versions');
                
                // Media Routes
                Route::apiResource('media', 'MediaController');
                Route::post('media/{id}/optimize', 'MediaController@optimize');
                Route::post('media/bulk', 'MediaController@bulkUpload');
                
                // User Routes
                Route::apiResource('users', 'UserController');
                Route::post('users/{id}/roles', 'UserController@updateRoles');
                Route::get('users/{id}/activity', 'UserController@activity');
                
                // Template Routes
                Route::apiResource('templates', 'TemplateController');
                Route::post('templates/{id}/compile', 'TemplateController@compile');
                Route::get('templates/{id}/preview', 'TemplateController@preview');
            });
    }

    private function registerWebRoutes(): void
    {
        Route::middleware(['web', 'cache.headers'])
            ->group(function() {
                // Public Content Routes
                Route::get('/', 'HomeController@index');
                Route::get('content/{slug}', 'ContentController@show');
                Route::get('category/{slug}', 'CategoryController@show');
                Route::get('tag/{slug}', 'TagController@show');
                Route::get('search', 'SearchController@index');
                
                // Authentication Routes
                Route::get('login', 'Auth\LoginController@showLoginForm');
                Route::post('login', 'Auth\LoginController@login');
                Route::post('logout', 'Auth\LoginController@logout');
                Route::get('register', 'Auth\RegisterController@showRegistrationForm');
                Route::post('register', 'Auth\RegisterController@register');
            });
    }

    private function registerAdminRoutes(): void
    {
        Route::middleware(['web', 'auth', 'admin'])
            ->prefix('admin')
            ->group(function() {
                // Dashboard Routes
                Route::get('/', 'Admin\DashboardController@index');
                Route::get('analytics', 'Admin\DashboardController@analytics');
                
                // Content Management
                Route::resource('content', 'Admin\ContentController');
                Route::resource('categories', 'Admin\CategoryController');
                Route::resource('tags', 'Admin\TagController');
                
                // Media Management
                Route::resource('media', 'Admin\MediaController');
                Route::get('media/browser', 'Admin\MediaController@browser');
                
                // User Management
                Route::resource('users', 'Admin\UserController');
                Route::resource('roles', 'Admin\RoleController');
                Route::resource('permissions', 'Admin\PermissionController');
                
                // System Settings
                Route::get('settings', 'Admin\SettingsController@index');
                Route::post('settings', 'Admin\SettingsController@update');
                Route::get('maintenance', 'Admin\MaintenanceController@index');
                Route::post('maintenance/clear-cache', 'Admin\MaintenanceController@clearCache');
            });
    }

    private function registerAssetRoutes(): void
    {
        Route::middleware(['web', 'cache.headers:public;max_age=31536000;etag'])
            ->prefix('assets')
            ->group(function() {
                Route::get('css/{file}', 'AssetController@css');
                Route::get('js/{file}', 'AssetController@js');
                Route::get('images/{path}', 'AssetController@image')->where('path', '.*');
                Route::get('fonts/{file}', 'AssetController@font');
            });
    }

    private function validateRequest(Request $request, SecurityContext $context): void
    {
        $this->validator->validateRequest($request);
        
        if (!$this->security->validateAccess($context)) {
            throw new UnauthorizedException('Access denied');
        }
    }

    private function checkRateLimits(Request $request, SecurityContext $context): void
    {
        if (!$this->rateLimiter->check($request, $context)) {
            throw new RateLimitExceededException('Rate limit exceeded');
        }
    }

    private function handleRequest(Request $request, SecurityContext $context): Response
    {
        $route = Route::getRoutes()->match($request);
        
        if ($this->shouldCacheResponse($route)) {
            return $this->handleCachedRequest($route, $request, $context);
        }
        
        return $route->run();
    }

    private function handleCachedRequest($route, Request $request, SecurityContext $context): Response
    {
        $cacheKey = $this->generateCacheKey($route, $request);
        
        return $this->cache->remember(
            $cacheKey,
            function() use ($route) {
                return $route->run();
            },
            $this->getCacheDuration($route)
        );
    }

    private function shouldCacheResponse($route): bool
    {
        return !$route->excluded && 
               $route->methods[0] === 'GET' && 
               !$route->parameterNames();
    }

    private function generateCacheKey($route, Request $request): string
    {
        return sprintf(
            'route:%s:%s:%s',
            $route->getName(),
            $request->getPathInfo(),
            md5(serialize($request->query()))
        );
    }

    private function getCacheDuration($route): int
    {
        return $route->cache ?? $this->config['default_cache_duration'];
    }

    private function createSecurityContext(Request $request): SecurityContext
    {
        return new SecurityContext([
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'method' => $request->method(),
            'path' => $request->path(),
            'user_id' => $request->user()?->id
        ]);
    }

    private function logRequestMetrics(Request $request, Response $response, float $startTime): void
    {
        $duration = microtime(true) - $startTime;
        
        $this->metrics->recordRequest([
            'path' => $request->path(),
            'method' => $request->method(),
            'status' => $response->getStatusCode(),
            'duration' => $duration,
            'memory' => memory_get_peak_usage(true)
        ]);
    }

    private function handleRequestError(\Exception $e, SecurityContext $context): Response
    {
        $this->metrics->incrementErrorCount($e);
        
        return response()->json([
            'error' => $e->getMessage(),
            'code' => $e->getCode()
        ], $this->getErrorStatusCode($e));
    }

    private function getErrorStatusCode(\Exception $e): int
    {
        return match (get_class($e)) {
            UnauthorizedException::class => 401,
            ForbiddenException::class => 403,
            NotFoundException::class => 404,
            ValidationException::class => 422,
            RateLimitExceededException::class => 429,
            default => 500
        };
    }
}
