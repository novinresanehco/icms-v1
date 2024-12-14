<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Core\CMSSystem;
use App\Core\Security\SecurityManager;
use App\Core\CMS\CoreCMSManager;
use App\Core\API\APISecurityManager; 
use App\Core\Cache\SecureCacheManager;
use App\Core\CMS\Media\SecureMediaManager;
use App\Core\Database\SecureTransactionManager;
use App\Core\CMS\Versioning\VersionControlManager;
use App\Core\Security\DataProtection\DataProtectionService;
use App\Core\Security\Audit\SecurityAudit;

class CMSServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->registerSecurityServices();
        $this->registerCoreServices();
        $this->registerCMSServices();
        $this->registerAPIServices();
        
        $this->app->singleton(CMSSystem::class, function($app) {
            return new CMSSystem(
                $app->make(SecurityManager::class),
                $app->make(CoreCMSManager::class),
                $app->make(APISecurityManager::class),
                $app->make(SecureCacheManager::class),
                $app->make(SecureMediaManager::class),
                $app->make(SecureTransactionManager::class),
                $app->make(VersionControlManager::class),
                $app->make(DataProtectionService::class),
                $app->make(SecurityAudit::class)
            );
        });
    }

    public function boot(): void
    {
        $this->bootSecurityServices();
        $this->bootCoreServices();
        $this->registerMiddleware();
        $this->registerCommands();
        $this->publishResources();
    }

    protected function registerSecurityServices(): void
    {
        $this->app->singleton(SecurityManager::class, function($app) {
            return new SecurityManager(
                $app['config']['cms.security'],
                $app->make(DataProtectionService::class),
                $app->make(SecurityAudit::class)
            );
        });

        $this->app->singleton(DataProtectionService::class, function($app) {
            return new DataProtectionService(
                $app['config']['cms.encryption'],
                $app['config']['cms.protection']
            );
        });

        $this->app->singleton(SecurityAudit::class, function($app) {
            return new SecurityAudit(
                $app['config']['cms.audit'],
                $app->make('log')
            );
        });
    }

    protected function registerCoreServices(): void
    {
        $this->app->singleton(SecureTransactionManager::class, function($app) {
            return new SecureTransactionManager(
                $app->make(SecurityManager::class),
                $app->make(DataProtectionService::class),
                $app->make(SecurityAudit::class),
                $app['config']['cms.transactions']
            );
        });

        $this->app->singleton(SecureCacheManager::class, function($app) {
            return new SecureCacheManager(
                $app->make(SecurityManager::class),
                $app->make(DataProtectionService::class),
                $app->make(SecurityAudit::class),
                $app['config']['cms.cache']
            );
        });
    }

    protected function registerCMSServices(): void
    {
        $this->app->singleton(CoreCMSManager::class, function($app) {
            return new CoreCMSManager(
                $app->make(SecurityManager::class),
                $app->make(SecureTransactionManager::class),
                $app->make(DataProtectionService::class),
                $app->make(SecurityAudit::class)
            );
        });

        $this->app->singleton(SecureMediaManager::class, function($app) {
            return new SecureMediaManager(
                $app->make(SecurityManager::class),
                $app->make(DataProtectionService::class),
                $app->make(SecureTransactionManager::class),
                $app->make(SecurityAudit::class),
                $app['config']['cms.media']
            );
        });

        $this->app->singleton(VersionControlManager::class, function($app) {
            return new VersionControlManager(
                $app->make(SecurityManager::class),
                $app->make(SecureTransactionManager::class),
                $app->make(DataProtectionService::class),
                $app->make(SecurityAudit::class)
            );
        });
    }

    protected function registerAPIServices(): void
    {
        $this->app->singleton(APISecurityManager::class, function($app) {
            return new APISecurityManager(
                $app->make(SecurityManager::class),
                $app->make(DataProtectionService::class),
                $app->make(SecureTransactionManager::class),
                $app->make(SecurityAudit::class),
                $app['config']['cms.api']
            );
        });
    }

    protected function bootSecurityServices(): void
    {
        $this->app->make(SecurityManager::class)->boot();
        $this->app->make(DataProtectionService::class)->boot();
        $this->app->make(SecurityAudit::class)->boot();
    }

    protected function bootCoreServices(): void
    {
        $this->app->make(SecureTransactionManager::class)->boot();
        $this->app->make(SecureCacheManager::class)->boot();
        $this->app->make(CoreCMSManager::class)->boot();
    }

    protected function registerMiddleware(): void
    {
        $router = $this->app['router'];

        $router->aliasMiddleware('cms.auth', \App\Http\Middleware\CMSAuthentication::class);
        $router->aliasMiddleware('cms.audit', \App\Http\Middleware\CMSAudit::class);
        $router->aliasMiddleware('cms.security', \App\Http\Middleware\CMSSecurity::class);
    }

    protected function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                \App\Console\Commands\CMSInstall::class,
                \App\Console\Commands\CMSAudit::class,
                \App\Console\Commands\CMSMaintenance::class
            ]);
        }
    }

    protected function publishResources(): void
    {
        $this->publishes([
            __DIR__.'/../config/cms.php' => config_path('cms.php'),
            __DIR__.'/../database/migrations' => database_path('migrations')
        ], 'cms');
    }
}
