<?php

namespace App\Core\Integration;

use Illuminate\Support\ServiceProvider;
use App\Core\{Security, Auth, CMS, Template, Infrastructure};

class CoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Security Layer
        $this->app->singleton(Security\SecurityManager::class);
        $this->app->singleton(Security\ValidationService::class);
        $this->app->singleton(Security\EncryptionService::class);
        $this->app->singleton(Security\AuditLogger::class);

        // Auth System
        $this->app->singleton(Auth\AuthManager::class);
        $this->app->singleton(Auth\SessionManager::class);
        $this->app->singleton(Auth\TwoFactorAuth::class);

        // CMS Core
        $this->app->singleton(CMS\ContentManager::class);
        $this->app->singleton(CMS\ContentRepository::class);
        $this->app->singleton(CMS\MediaHandler::class);

        // Template System
        $this->app->singleton(Template\TemplateManager::class);
        $this->app->singleton(Template\ComponentRegistry::class);
        $this->app->singleton(Template\ThemeManager::class);

        // Infrastructure
        $this->app->singleton(Infrastructure\CacheManager::class);
        $this->app->singleton(Infrastructure\ErrorHandler::class);
        $this->app->singleton(Infrastructure\MonitoringService::class);
    }

    public function boot(): void
    {
        $this->registerMiddleware();
        $this->registerComponents();
        $this->initializeMonitoring();
    }

    private function registerMiddleware(): void
    {
        $router = $this->app['router'];
        
        $router->aliasMiddleware('auth.cms', Auth\Middleware\CmsAuthentication::class);
        $router->aliasMiddleware('auth.2fa', Auth\Middleware\TwoFactorAuth::class);
        $router->aliasMiddleware('role', Auth\Middleware\RoleCheck::class);
    }

    private function registerComponents(): void
    {
        $registry = $this->app->make(Template\ComponentRegistry::class);
        
        $registry->register('admin.table', new Template\Components\AdminTable);
        $registry->register('admin.form', new Template\Components\AdminForm);
        $registry->register('admin.nav', new Template\Components\AdminNavigation);
    }

    private function initializeMonitoring(): void
    {
        $monitor = $this->app->make(Infrastructure\MonitoringService::class);
        
        // Register critical error handlers
        $this->app->make(Infrastructure\ErrorHandler::class);
        
        // Initialize real-time metrics
        $monitor->recordMetric('system.boot', microtime(true));
    }
}

namespace App\Core\Auth\Middleware;

use Closure;
use App\Core\Auth\AuthManager;
use App\Core\Exceptions\AuthException;

class CmsAuthentication
{
    private AuthManager $auth;

    public function __construct(AuthManager $auth)
    {
        $this->auth = $auth;
    }

    public function handle($request, Closure $next)
    {
        $token = $request->bearerToken();

        if (!$token) {
            throw new AuthException('Authentication required');
        }

        $user = $this->auth->validateSession($token);
        $request->setUserResolver(fn() => $user);

        return $next($request);
    }
}

class TwoFactorAuth
{
    private AuthManager $auth;

    public function handle($request, Closure $next)
    {
        if (!$request->session()->has('2fa_verified')) {
            throw new AuthException('2FA required');
        }

        return $next($request);
    }
}

class RoleCheck
{
    public function handle($request, Closure $next, string $role)
    {
        $user = $request->user();

        if (!$user || $user->role !== $role) {
            throw new AuthException('Unauthorized role');
        }

        return $next($request);
    }
}

namespace App\Core\Bootstrap;

class Application extends \Illuminate\Foundation\Application
{
    protected function registerBaseServiceProviders(): void
    {
        parent::registerBaseServiceProviders();
        $this->register(new \App\Core\Integration\CoreServiceProvider($this));
    }

    protected function bootstrappers(): array
    {
        return array_merge(parent::bootstrappers(), [
            \App\Core\Bootstrap\SecurityBootstrapper::class
        ]);
    }
}

class SecurityBootstrapper
{
    public function bootstrap(\Illuminate\Contracts\Foundation\Application $app): void
    {
        $security = $app->make(\App\Core\Security\SecurityManager::class);
        $security->initializeSecurity();
        
        $monitor = $app->make(\App\Core\Infrastructure\MonitoringService::class);
        $monitor->recordMetric('security.boot', microtime(true));
    }
}
