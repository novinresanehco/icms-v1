<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class CoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SecurityManager::class);
        $this->app->singleton(ValidationService::class);
        $this->app->singleton(SystemMonitor::class);
        $this->app->singleton(CacheManager::class);
    }
}

class SecurityServiceProvider extends ServiceProvider 
{
    public function register(): void
    {
        $this->app->singleton(AccessControl::class);
        $this->app->singleton(AuditLogger::class);
        
        $this->app->bind(SecurityManager::class, function($app) {
            return new SecurityManager(
                $app->make(AccessControl::class),
                $app->make(AuditLogger::class),
                config('security')
            );
        });
    }
}

class MonitoringServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MetricsCollector::class);
        $this->app->singleton(AlertManager::class);
        
        $this->app->bind(SystemMonitor::class, function($app) {
            return new SystemMonitor(
                $app->make(MetricsCollector::class),
                $app->make(AlertManager::class),
                config('monitoring')
            );
        });
    }
}
