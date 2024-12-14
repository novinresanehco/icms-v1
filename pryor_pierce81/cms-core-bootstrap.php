<?php

namespace App\Core;

use Illuminate\Support\ServiceProvider;
use Illuminate\Contracts\Foundation\Application;
use App\Core\Security\SecurityManager;
use App\Core\Events\EventManager;

class CMSServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->registerCoreServices();
        $this->registerSecurityServices();
        $this->registerContentServices();
        $this->registerMediaServices();
        $this->registerCacheServices();
        $this->registerEventServices();
        $this->registerMonitoringServices();
    }

    public function boot(): void
    {
        $this->bootConfiguration();
        $this->bootMiddleware();
        $this->bootMigrations();
        $this->bootCommands();
        $this->bootScheduler();
        $this->bootEventListeners();
        $this->bootSecurityProtocols();
    }

    protected function registerCoreServices(): void
    {
        $this->app->singleton(CMSManager::class, function ($app) {
            return new CMSManager(
                $app[SecurityManager::class],
                $app[EventManager::class],
                $app->config['cms']
            );
        });

        $this->app->alias(CMSManager::class, 'cms');
    }

    protected function registerSecurityServices(): void
    {
        $this->app->singleton(SecurityManager::class, function ($app) {
            return new SecurityManager(
                $app['encrypter'],
                $app['validator'],
                $app['logger'],
                $app->config['security']
            );
        });

        $this->app->singleton(AuthenticationManager::class);
        $this->app->singleton(AuthorizationManager::class);
        $this->app->singleton(ValidationManager::class);
    }

    protected function registerContentServices(): void
    {
        $this->app->singleton(ContentManager::class);
        $this->app->singleton(TemplateManager::class);
        $this->app->singleton(VersionManager::class);
        $this->app->singleton(SearchManager::class);
    }

    protected function registerMediaServices(): void
    {
        $this->app->singleton(MediaManager::class);
        $this->app->singleton(ImageProcessor::class);
        $this->app->singleton(FileManager::class);
    }

    protected function registerCacheServices(): void
    {
        $this->app->singleton(CacheManager::class, function ($app) {
            return new CacheManager(
                $app['cache.store'],
                $app[SecurityManager::class],
                $app->config['cache']
            );
        });
    }

    protected function registerEventServices(): void
    {
        $this->app->singleton(EventManager::class, function ($app) {
            return new EventManager(
                $app['events'],
                $app[SecurityManager::class],
                $app->config['events']
            );
        });
    }

    protected function registerMonitoringServices(): void
    {
        $this->app->singleton(PerformanceManager::class);
        $this->app->singleton(MonitoringManager::class);
        $this->app->singleton(LogManager::class);
        $this->app->singleton(AuditManager::class);
    }

    protected function bootConfiguration(): void
    {
        $this->publishes([
            __DIR__ . '/../config/cms.php' => config_path('cms.php'),
            __DIR__ . '/../config/security.php' => config_path('security.php'),
        ], 'cms-config');

        $this->mergeConfigFrom(__DIR__ . '/../config/cms.php', 'cms');
        $this->mergeConfigFrom(__DIR__ . '/../config/security.php', 'security');
    }

    protected function bootMiddleware(): void
    {
        $this->app['router']->aliasMiddleware('cms.auth', CMSAuthMiddleware::class);
        $this->app['router']->aliasMiddleware('cms.validate', CMSValidationMiddleware::class);
        $this->app['router']->aliasMiddleware('cms.security', CMSSecurityMiddleware::class);

        $this->app['router']->middlewareGroup('cms', [
            'cms.auth',
            'cms.validate',
            'cms.security'
        ]);
    }

    protected function bootMigrations(): void
    {
        if ($this->app->runningInConsole()) {
            $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

            $this->publishes([
                __DIR__ . '/../database/migrations/' => database_path('migrations'),
            ], 'cms-migrations');
        }
    }

    protected function bootCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                CMSInstallCommand::class,
                CMSUpdateCommand::class,
                CMSBackupCommand::class,
                CMSRestoreCommand::class,
                CMSOptimizeCommand::class,
            ]);
        }
    }

    protected function bootScheduler(): void
    {
        $this->app->booted(function () {
            $schedule = $this->app->make('Illuminate\Console\Scheduling\Schedule');

            $schedule->command('cms:backup')->daily();
            $schedule->command('cms:optimize')->daily();
            $schedule->command('cms:audit')->weekly();
        });
    }

    protected function bootEventListeners(): void
    {
        $events = $this->app['events'];

        $events->listen('cms.content.created', ContentCreatedListener::class);
        $events->listen('cms.content.updated', ContentUpdatedListener::class);
        $events->listen('cms.content.deleted', ContentDeletedListener::class);
        $events->listen('cms.security.breach', SecurityBreachListener::class);
        $events->listen('cms.performance.threshold', PerformanceThresholdListener::class);
    }

    protected function bootSecurityProtocols(): void
    {
        $security = $this->app[SecurityManager::class];
        $security->enforceSecurityHeaders();
        $security->validateInstallation();
        $security->monitorSecurityEvents();
    }

    protected function registerErrorHandlers(): void
    {
        $this->app->singleton(
            Illuminate\Contracts\Debug\ExceptionHandler::class,
            CMSExceptionHandler::class
        );

        error_reporting(E_ALL);
        set_error_handler([$this, 'handleError']);
        set_exception_handler([$this, 'handleException']);
        register_shutdown_function([$this, 'handleShutdown']);
    }

    public function handleError($level, $message, $file = '', $line = 0): void
    {
        if (error_reporting() & $level) {
            throw new \ErrorException($message, 0, $level, $file, $line);
        }
    }

    public function handleException(\Throwable $e): void
    {
        $this->app[LogManager::class]->logException($e);
        $this->app[SecurityManager::class]->handleException($e);
    }

    public function handleShutdown(): void
    {
        if (!is_null($error = error_get_last()) && $this->isFatal($error['type'])) {
            $this->handleException(new \ErrorException(
                $error['message'], 0, $error['type'],
                $error['file'], $error['line']
            ));
        }
    }

    protected function isFatal($type): bool
    {
        return in_array($type, [E_COMPILE_ERROR, E_CORE_ERROR, E_ERROR, E_PARSE]);
    }
}
