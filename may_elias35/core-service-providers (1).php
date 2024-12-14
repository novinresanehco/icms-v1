<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Core\Security\SecurityManager;
use App\Core\Cache\CacheManager;
use App\Core\Database\DatabaseManager;
use App\Core\Monitoring\SystemMonitor;

class CoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SecurityManager::class, function ($app) {
            return new SecurityManager(
                $app->make(ValidationService::class),
                $app->make(AuditLogger::class),
                $app->make(ProtectionManager::class),
                $app['config']['security']
            );
        });

        $this->app->singleton(CacheManager::class, function ($app) {
            return new CacheManager(
                $app->make(SecurityContext::class),
                $app->make(SystemMonitor::class),
                $app->make(LoggerInterface::class),
                $app['config']['cache']
            );
        });

        $this->app->singleton(DatabaseManager::class, function ($app) {
            return new DatabaseManager(
                $app->make(SecurityContext::class),
                $app->make(SystemMonitor::class),
                $app->make(ConnectionManager::class),
                $app['config']['database']
            );
        });
    }

    public function boot(): void
    {
        $this->registerMiddleware();
        $this->registerListeners();
        $this->bootSecurityServices();
    }

    private function registerMiddleware(): void
    {
        $this->app['router']->aliasMiddleware(
            'security',
            \App\Http\Middleware\SecurityMiddleware::class
        );
        
        $this->app['router']->middlewareGroup('critical', [
            \App\Http\Middleware\SecurityMiddleware::class,
            \App\Http\Middleware\MonitoringMiddleware::class,
            \App\Http\Middleware\ValidationMiddleware::class
        ]);
    }

    private function bootSecurityServices(): void
    {
        $security = $this->app->make(SecurityManager::class);
        $security->bootCriticalServices();
    }
}

class SecurityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ValidationService::class, function ($app) {
            return new ValidationService($app['config']['validation']);
        });

        $this->app->singleton(AuditLogger::class, function ($app) {
            return new AuditLogger(
                $app->make(ValidationService::class),
                $app->make(SystemMonitor::class),
                $app['config']['audit']
            );
        });

        $this->app->singleton(ProtectionManager::class, function ($app) {
            return new ProtectionManager(
                $app->make(SecurityConfig::class),
                $app->make(SystemMonitor::class),
                $app->make(AlertManager::class)
            );
        });
    }

    public function boot(): void
    {
        $this->bootSecurityMiddleware();
        $this->registerSecurityCommands();
        $this->publishSecurityConfig();
    }

    private function bootSecurityMiddleware(): void
    {
        $this->app['router']->middleware([
            'auth.multi-factor' => \App\Http\Middleware\MultiFactorAuth::class,
            'auth.session' => \App\Http\Middleware\SessionSecurity::class,
            'auth.api' => \App\Http\Middleware\ApiSecurity::class
        ]);
    }
}

class MonitoringServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SystemMonitor::class, function ($app) {
            return new SystemMonitor(
                $app->make(MetricsCollector::class),
                $app->make(AlertManager::class),
                $app['config']['monitoring']
            );
        });

        $this->app->singleton(MetricsCollector::class, function ($app) {
            return new MetricsCollector(
                $app['cache.store'],
                $app['config']['metrics']
            );
        });
    }

    public function boot(): void
    {
        $this->bootMonitoringServices();
        $this->registerMonitoringCommands();
        $this->publishMonitoringConfig();
    }

    private function bootMonitoringServices(): void
    {
        $monitor = $this->app->make(SystemMonitor::class);
        $monitor->startCriticalMonitoring();
    }
}
