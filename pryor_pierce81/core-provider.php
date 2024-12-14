<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Core\Security\{
    SecurityManager,
    AccessControlManager,
    ValidationManager,
    AuditManager
};
use App\Core\Cache\CacheManager;
use App\Core\Content\ContentManager;

class CoreServiceProvider extends ServiceProvider
{
    protected array $singletons = [
        SecurityManager::class => SecurityManager::class,
        AccessControlManager::class => AccessControlManager::class,
        ValidationManager::class => ValidationManager::class,
        AuditManager::class => AuditManager::class,
        CacheManager::class => CacheManager::class,
        ContentManager::class => ContentManager::class,
    ];

    public function register(): void
    {
        $this->registerConfig();
        $this->registerServices();
        $this->registerRepositories();
        $this->registerFactories();
    }

    public function boot(): void
    {
        $this->bootSecurityServices();
        $this->bootCacheServices();
        $this->bootAuditServices();
        $this->bootValidationServices();
    }

    protected function registerConfig(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/security.php', 'security');
        $this->mergeConfigFrom(__DIR__.'/../../config/cache.php', 'cache');
        $this->mergeConfigFrom(__DIR__.'/../../config/audit.php', 'audit');
        
        $this->app->singleton('security.config', function() {
            return new SecurityConfig($this->app['config']['security']);
        });
    }

    protected function registerServices(): void
    {
        // Security Services
        $this->app->singleton(SecurityManager::class, function($app) {
            return new SecurityManager(
                $app[ValidationManager::class],
                $app[EncryptionService::class],
                $app[AuditManager::class],
                $app[AccessControlManager::class],
                $app['security.config']
            );
        });

        // Cache Services
        $this->app->singleton(CacheManager::class, function($app) {
            return new CacheManager(
                $app[SecurityManager::class],
                $app['config']['cache']
            );
        });

        // Content Management
        $this->app->singleton(ContentManager::class, function($app) {
            return new ContentManager(
                $app[SecurityManager::class],
                $app[CacheManager::class],
                $app[ContentValidator::class],
                $app[ContentRepository::class],
                $app[AuditManager::class]
            );
        });
    }

    protected function registerRepositories(): void
    {
        $this->app->bind(ContentRepository::class, function($app) {
            return new ContentRepository(
                $app['db'],
                $app[CacheManager::class]
            );
        });
    }

    protected function registerFactories(): void
    {
        $this->app->singleton(QueryBuilder::class, function($app) {
            return new QueryBuilder(
                $app['db'],
                $app[SecurityManager::class]
            );
        });
    }

    protected function bootSecurityServices(): void
    {
        $security = $this->app[SecurityManager::class];
        
        // Register global middleware
        $this->app['router']->aliasMiddleware('security', SecurityMiddleware::class);
        $this->app['router']->middlewareGroup('api', [SecurityMiddleware::class]);
        
        // Register security events
        $this->app['events']->listen(SecurityEvent::class, 
            [SecurityEventHandler::class, 'handle']
        );
    }

    protected function bootCacheServices(): void
    {
        $cache = $this->app[CacheManager::class];
        
        // Register cache events
        $this->app['events']->listen(CacheEvent::class, 
            [CacheEventHandler::class, 'handle']
        );
    }

    protected function bootAuditServices(): void
    {
        $audit = $this->app[AuditManager::class];
        
        // Register audit events
        $this->app['events']->listen(AuditEvent::class, 
            [AuditEventHandler::class, 'handle']
        );
        
        // Register model observers
        Content::observe(ContentObserver::class);
    }

    protected function bootValidationServices(): void
    {
        $validation = $this->app[ValidationManager::class];
        
        // Register custom validation rules
        $validation->extend('secure_string', SecureStringRule::class);
        $validation->extend('no_scripts', NoScriptsRule::class);
        $validation->extend('safe_html', SafeHtmlRule::class);
    }
}
