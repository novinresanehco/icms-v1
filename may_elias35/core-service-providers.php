<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Core\Security\{SecurityManager, TokenManager};
use App\Core\Auth\AuthenticationSystem;
use App\Core\CMS\{ContentManager, MediaHandler};
use App\Core\Template\{TemplateManager, ThemeManager, ComponentRegistry};
use App\Core\Infrastructure\{CacheManager, SystemMonitor, ErrorHandler};

class CMSServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Core Security
        $this->app->singleton(SecurityManager::class, function($app) {
            return new SecurityManager(
                $app->make(ValidationService::class),
                $app->make(EncryptionService::class),
                $app->make(AuditService::class),
                config('security')
            );
        });

        // Authentication
        $this->app->singleton(AuthenticationSystem::class, function($app) {
            return new AuthenticationSystem(
                $app->make(SecurityManager::class),
                $app->make(CacheManager::class),
                $app->make(TokenManager::class)
            );
        });

        // Content Management
        $this->app->singleton(ContentManager::class, function($app) {
            return new ContentManager(
                $app->make(SecurityManager::class),
                $app->make(CacheManager::class),
                $app->make(ContentRepository::class),
                $app->make(MediaHandler::class)
            );
        });

        // Template System
        $this->app->singleton(TemplateManager::class, function($app) {
            return new TemplateManager(
                $app->make(SecurityManager::class),
                $app->make(CacheManager::class),
                $app->make('view'),
                config('templates')
            );
        });

        // Infrastructure
        $this->app->singleton(SystemMonitor::class, function($app) {
            return new SystemMonitor(
                $app->make(MetricsCollector::class)
            );
        });

        $this->app->singleton(ErrorHandler::class, function($app) {
            return new ErrorHandler(
                $app->make(SystemMonitor::class)
            );
        });
    }

    public function boot(): void
    {
        $this->bootSecurityMiddleware();
        $this->bootErrorHandlers();
        $this->bootCacheConfig();
    }

    private function bootSecurityMiddleware(): void
    {
        $this->app['router']->aliasMiddleware('cms.auth', \App\Http\Middleware\CMSAuthentication::class);
        $this->app['router']->aliasMiddleware('cms.authorize', \App\Http\Middleware\CMSAuthorization::class);
    }

    private function bootErrorHandlers(): void
    {
        $this->app->make(ErrorHandler::class)->register();
    }

    private function bootCacheConfig(): void
    {
        $config = $this->app['config'];
        $config->set('cache.default', env('CMS_CACHE_DRIVER', 'redis'));
        $config->set('cache.prefix', env('CMS_CACHE_PREFIX', 'cms'));
    }
}

class CMSMiddleware
{
    private SecurityManager $security;
    private AuthenticationSystem $auth;

    public function __construct(SecurityManager $security, AuthenticationSystem $auth)
    {
        $this->security = $security;
        $this->auth = $auth;
    }

    public function handle($request, \Closure $next)
    {
        if (!$this->auth->verify($request->bearerToken())) {
            throw new AuthenticationException('Invalid token');
        }

        return $this->security->executeSecureOperation(
            fn() => $next($request),
            ['action' => 'handle_request']
        );
    }
}

class CMSKernel extends HttpKernel
{
    protected $middlewarePriority = [
        \App\Http\Middleware\CMSAuthentication::class,
        \App\Http\Middleware\CMSAuthorization::class,
        \Illuminate\Session\Middleware\StartSession::class,
        \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \Illuminate\Routing\Middleware\SubstituteBindings::class,
    ];

    protected $middlewareGroups = [
        'cms' => [
            \App\Http\Middleware\CMSAuthentication::class,
            \App\Http\Middleware\CMSAuthorization::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \App\Http\Middleware\VerifyCsrfToken::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ]
    ];
}
