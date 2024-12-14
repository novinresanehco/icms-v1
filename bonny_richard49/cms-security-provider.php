<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Core\Security\{
    CoreSecuritySystem,
    ValidationService,
    EncryptionService,
    AuditService,
    MonitoringService
};

/**
 * CMS Security Provider - Configures core security services
 * CRITICAL: This provider initializes security infrastructure
 */
class CMSSecurityProvider extends ServiceProvider
{
    public function register(): void
    {
        // Register core security system
        $this->app->singleton(CoreSecuritySystem::class, function ($app) {
            return new CoreSecuritySystem(
                $app->make(ValidationService::class),
                $app->make(EncryptionService::class),
                $app->make(AuditService::class),
                $app->make(MonitoringService::class),
                $app->make('log')
            );
        });

        // Register security services
        $this->registerSecurityServices();

        // Configure security middleware
        $this->configureSecurityMiddleware();
    }

    /**
     * Register critical security services
     */
    protected function registerSecurityServices(): void
    {
        // Validation service
        $this->app->singleton(ValidationService::class, function ($app) {
            return new ValidationService(
                config('security.validation'),
                $app->make('cache'),
                $app->make('log')
            );
        });

        // Encryption service
        $this->app->singleton(EncryptionService::class, function ($app) {
            return new EncryptionService(
                config('security.encryption'),
                $app->make('cache')
            );
        });

        // Audit service
        $this->app->singleton(AuditService::class, function ($app) {
            return new AuditService(
                $app->make('db'),
                $app->make('log'),
                config('security.audit')
            );
        });

        // Monitoring service
        $this->app->singleton(MonitoringService::class, function ($app) {
            return new MonitoringService(
                $app->make('cache'),
                $app->make('log'),
                config('security.monitoring')
            );
        });
    }

    /**
     * Configure security middleware stack
     */
    protected function configureSecurityMiddleware(): void
    {
        $this->app['router']->middlewareGroup('cms.security', [
            \App\Http\Middleware\ValidateSecurityHeaders::class,
            \App\Http\Middleware\ValidateRequestIntegrity::class,
            \App\Http\Middleware\EnforceSecurityPolicy::class,
        ]);
    }

    /**
     * Register security policies
     */
    public function boot(): void
    {
        $this->registerSecurityPolicies();
        $this->configureSecurityDefaults();
        $this->initializeSecurityMonitoring();
    }

    /**
     * Register security policies
     */
    protected function registerSecurityPolicies(): void
    {
        Gate::define('execute-critical-operation', function ($user, $operation) {
            return (new CoreSecuritySystem())->validateCriticalOperation(
                $user,
                $operation
            );
        });
    }

    /**
     * Configure security defaults
     */
    protected function configureSecurityDefaults(): void
    {
        $config = config('security.defaults');

        foreach ($config as $key => $value) {
            Config::set("security.{$key}", $value);
        }
    }

    /**
     * Initialize security monitoring
     */
    protected function initializeSecurityMonitoring(): void
    {
        $monitor = $this->app->make(MonitoringService::class);
        $monitor->initialize([
            'tracking_enabled' => true,
            'alert_threshold' => config('security.alert_threshold'),
            'log_level' => 'debug'
        ]);
    }
}
