<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Core\{
    Security\SecurityManager,
    Security\ValidationService,
    Performance\PerformanceOptimizer,
    Cache\CacheManager,
    Recovery\RecoverySystem,
    Infrastructure\InfrastructureManager
};

class CriticalSystemServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Register Core Security
        $this->app->singleton(SecurityManager::class, function ($app) {
            return new SecurityManager(
                $app->make(ValidationService::class),
                $app->make(EncryptionService::class),
                $app->make(AuditLogger::class),
                $app['config']['security']
            );
        });

        // Register Performance Optimizer 
        $this->app->singleton(PerformanceOptimizer::class, function ($app) {
            return new PerformanceOptimizer(
                $app->make(CacheManager::class),
                $app->make(MonitoringService::class),
                $app->make(MetricsCollector::class),
                $app['config']['performance']
            );
        });

        // Register Recovery System
        $this->app->singleton(RecoverySystem::class, function ($app) {
            return new RecoverySystem(
                $app->make(BackupManager::class),
                $app->make(StateManager::class),
                $app->make(EmergencyProtocol::class),
                $app['config']['recovery']
            );
        });

        // Register Infrastructure Manager
        $this->app->singleton(InfrastructureManager::class, function ($app) {
            return new InfrastructureManager(
                $app->make(ResourceManager::class),
                $app->make(ConnectionManager::class),
                $app->make(SystemMonitor::class),
                $app['config']['infrastructure']
            );
        });
    }

    public function boot(): void
    {
        // Initialize Core Services
        $this->app->make(SecurityManager::class)->initialize();
        $this->app->make(PerformanceOptimizer::class)->initialize();
        $this->app->make(SystemMonitor::class)->startMonitoring();
        
        // Register Critical Middleware
        $this->registerCriticalMiddleware();
        
        // Set Up Error Handlers
        $this->setupErrorHandlers();
    }

    private function registerCriticalMiddleware(): void
    {
        $this->app['router']->middlewareGroup('critical', [
            ValidateSecurityState::class,
            MonitorPerformance::class,
            TrackSystemState::class,
            EnforceRateLimits::class
        ]);
    }

    private function setupErrorHandlers(): void
    {
        $this->app->make(RecoverySystem::class)->registerHandlers();
        $this->app->make(EmergencyProtocol::class)->initialize();
    }
}

class SecurityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ValidationService::class);
        $this->app->singleton(EncryptionService::class);
        $this->app->singleton(AuditLogger::class);
        
        $this->app->when(SecurityManager::class)
             ->needs('$config')
             ->give($this->app['config']['security']);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/security.php' => config_path('security.php'),
        ], 'security-config');
    }
}

class PerformanceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CacheManager::class);
        $this->app->singleton(QueryOptimizer::class);
        $this->app->singleton(MetricsCollector::class);
        
        $this->app->when(PerformanceOptimizer::class)
             ->needs('$config')
             ->give($this->app['config']['performance']);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/performance.php' => config_path('performance.php'),
        ], 'performance-config');
    }
}

class InfrastructureServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ResourceManager::class);
        $this->app->singleton(ConnectionManager::class);
        $this->app->singleton(SystemMonitor::class);
        
        $this->app->when(InfrastructureManager::class)
             ->needs('$config')
             ->give($this->app['config']['infrastructure']);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/infrastructure.php' => config_path('infrastructure.php'),
        ], 'infrastructure-config');
    }
}

class RecoveryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(BackupManager::class);
        $this->app->singleton(StateManager::class);
        $this->app->singleton(EmergencyProtocol::class);
        
        $this->app->when(RecoverySystem::class)
             ->needs('$config')
             ->give($this->app['config']['recovery']);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/recovery.php' => config_path('recovery.php'),
        ], 'recovery-config');
    }
}
