<?php

namespace App\Providers;

class CoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SecurityManagerInterface::class, SecurityManager::class);
        $this->app->singleton(CacheManagerInterface::class, CacheManager::class);
        $this->app->singleton(ValidationInterface::class, ValidationService::class);
        $this->app->singleton(AuditLoggerInterface::class, AuditLogger::class);
        $this->app->singleton(MetricsCollectorInterface::class, MetricsCollector::class);
        $this->app->singleton(MonitorInterface::class, SystemMonitor::class);
        $this->app->singleton(ErrorHandlerInterface::class, ErrorHandler::class);

        $this->app->when(ContentManager::class)
            ->needs(RepositoryInterface::class)
            ->give(ContentRepository::class);

        $this->app->when(MediaManager::class)
            ->needs(RepositoryInterface::class)
            ->give(MediaRepository::class);
    }

    public function boot(): void
    {
        $this->registerErrorHandlers();
        $this->registerSecurityMiddleware();
        $this->registerCacheDrivers();
        $this->registerMonitoring();
    }

    private function registerErrorHandlers(): void
    {
        $handler = $this->app->make(ErrorHandlerInterface::class);
        $this->app['events']->listen(QueryException::class, [$handler, 'handle']);
        $this->app['events']->listen(ValidationException::class, [$handler, 'handle']);
        $this->app['events']->listen(AuthenticationException::class, [$handler, 'handle']);
        $this->app['events']->listen(SecurityException::class, [$handler, 'handle']);
    }

    private function registerSecurityMiddleware(): void
    {
        $router = $this->app['router'];
        
        $router->aliasMiddleware('auth', AuthenticateRequests::class);
        $router->aliasMiddleware('permission', ValidatePermissions::class);
        
        $router->middlewareGroup('api', [
            'auth',
            'permission',
            ValidateSecureRequests::class,
        ]);
    }

    private function registerCacheDrivers(): void
    {
        $this->app['cache']->extend('secure', function() {
            return new SecureCacheStore(
                $this->app->make(SecurityManagerInterface::class)
            );
        });
    }

    private function registerMonitoring(): void
    {
        $monitor = $this->app->make(MonitorInterface::class);
        
        $this->app['events']->listen(QueryExecuted::class, function($query) use ($monitor) {
            $monitor->recordMetrics();
        });

        $this->app['events']->listen(RequestHandled::class, function($request) use ($monitor) {
            $monitor->detectAnomalies();
        });
    }
}

class AuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AuthenticationInterface::class, AuthenticationManager::class);
        $this->app->singleton(AuthorizationInterface::class, AuthorizationManager::class);
        $this->app->singleton(TokenManagerInterface::class, TokenManager::class);
    }

    public function boot(): void
    {
        $this->registerPolicies();
        $this->registerPermissions();
        $this->registerRoles();
    }

    private function registerPolicies(): void
    {
        Gate::policy(Content::class, ContentPolicy::class);
        Gate::policy(Media::class, MediaPolicy::class);
    }

    private function registerPermissions(): void
    {
        $registry = $this->app->make(PermissionRegistry::class);
        
        $registry->define('content.create', 'Create new content');
        $registry->define('content.edit', 'Edit existing content');
        $registry->define('content.delete', 'Delete content');
        $registry->define('media.upload', 'Upload media files');
        $registry->define('media.manage', 'Manage media library');
    }

    private function registerRoles(): void
    {
        $manager = $this->app->make(RoleManager::class);

        $manager->define('admin', 'System Administrator', ['*']);
        $manager->define('editor', 'Content Editor', [
            'content.*',
            'media.*'
        ]);
        $manager->define('author', 'Content Author', [
            'content.create',
            'content.edit',
            'media.upload'
        ]);
    }
}
