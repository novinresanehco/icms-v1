<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Core\Security\{
    SecurityManager,
    SecurityManagerInterface
};
use App\Core\CMS\{
    CMSService,
    CMSServiceInterface
};
use App\Core\Infrastructure\{
    InfrastructureManager,
    InfrastructureManagerInterface
};
use App\Core\Database\{
    DatabaseManager,
    DatabaseManagerInterface
};
use App\Core\Cache\{
    CacheManager,
    CacheManagerInterface
};
use App\Core\Monitoring\{
    MonitoringService,
    MonitoringServiceInterface
};
use App\Core\Validation\{
    ValidationService,
    ValidationServiceInterface
};
use App\Core\Audit\{
    AuditManager,
    AuditManagerInterface
};
use App\Core\Media\{
    MediaManager,
    MediaManagerInterface
};

class CoreServiceProvider extends ServiceProvider
{
    /**
     * Critical service bindings
     */
    public function register(): void
    {
        // Security Layer - Most Critical
        $this->app->singleton(SecurityManagerInterface::class, function ($app) {
            return new SecurityManager(
                config('security'),
                $app->make(MonitoringServiceInterface::class),
                $app->make(AuditManagerInterface::class)
            );
        });

        // Monitoring Layer - Must be initialized early
        $this->app->singleton(MonitoringServiceInterface::class, function ($app) {
            return new MonitoringService(
                config('monitoring'),
                $app->make(CacheManagerInterface::class)
            );
        });

        // Validation Layer
        $this->app->singleton(ValidationServiceInterface::class, function ($app) {
            return new ValidationService(
                $app->make(SecurityManagerInterface::class),
                $app->make(MonitoringServiceInterface::class),
                config('validation')
            );
        });

        // Infrastructure Layer
        $this->app->singleton(InfrastructureManagerInterface::class, function ($app) {
            return new InfrastructureManager(
                $app->make(SecurityManagerInterface::class),
                $app->make(MonitoringServiceInterface::class),
                $app->make(CacheManagerInterface::class),
                config('infrastructure')
            );
        });

        // Database Layer
        $this->app->singleton(DatabaseManagerInterface::class, function ($app) {
            return new DatabaseManager(
                $app->make(SecurityManagerInterface::class),
                $app->make(MonitoringServiceInterface::class),
                $app->make(ValidationServiceInterface::class),
                config('database')
            );
        });

        // Cache Layer
        $this->app->singleton(CacheManagerInterface::class, function ($app) {
            return new CacheManager(
                $app->make(SecurityManagerInterface::class),
                $app->make(MonitoringServiceInterface::class),
                $app->make(ValidationServiceInterface::class),
                config('cache')
            );
        });

        // CMS Layer
        $this->app->singleton(CMSServiceInterface::class, function ($app) {
            return new CMSService(
                $app->make(SecurityManagerInterface::class),
                $app->make(DatabaseManagerInterface::class),
                $app->make(CacheManagerInterface::class),
                $app->make(MediaManagerInterface::class),
                config('cms')
            );
        });

        // Media Layer
        $this->app->singleton(MediaManagerInterface::class, function ($app) {
            return new MediaManager(
                $app->make(SecurityManagerInterface::class),
                $app->make(ValidationServiceInterface::class),
                $app->make(MonitoringServiceInterface::class),
                $app->make(InfrastructureManagerInterface::class),
                config('media')
            );
        });

        // Audit Layer
        $this->app->singleton(AuditManagerInterface::class, function ($app) {
            return new AuditManager(
                $app->make(SecurityManagerInterface::class),
                $app->make(MonitoringServiceInterface::class),
                $app->make(DatabaseManagerInterface::class),
                config('audit')
            );
        });
    }

    /**
     * Critical service initialization and verification
     */
    public function boot(): void
    {
        // Verify critical services
        $this->verifyCriticalServices();

        // Initialize monitoring
        $this->initializeMonitoring();

        // Setup audit trail
        $this->setupAuditTrail();

        // Verify security configuration
        $this->verifySecurityConfiguration();
    }

    /**
     * Verify all critical services are properly initialized
     */
    private function verifyCriticalServices(): void
    {
        $requiredServices = [
            SecurityManagerInterface::class,
            MonitoringServiceInterface::class,
            ValidationServiceInterface::class,
            DatabaseManagerInterface::class,
            CacheManagerInterface::class,
            CMSServiceInterface::class,
            MediaManagerInterface::class,
            AuditManagerInterface::class
        ];

        foreach ($requiredServices as $service) {
            if (!$this->app->bound($service)) {
                throw new \RuntimeException("Critical service not bound: $service");
            }
        }
    }

    /**
     * Initialize system monitoring
     */
    private function initializeMonitoring(): void
    {
        $monitor = $this->app->make(MonitoringServiceInterface::class);
        $monitor->startOperation('system.boot');
    }

    /**
     * Setup system audit trail
     */
    private function setupAuditTrail(): void
    {
        $audit = $this->app->make(AuditManagerInterface::class);
        $audit->logCriticalEvent('system.boot', [
            'timestamp' => now(),
            'environment' => app()->environment(),
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version()
        ], ['context' => 'system_boot']);
    }

    /**
     * Verify security configuration
     */
    private function verifySecurityConfiguration(): void
    {
        $security = $this->app->make(SecurityManagerInterface::class);
        $security->verifyConfiguration();
    }
}
