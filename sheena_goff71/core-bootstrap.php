<?php

namespace App\Core;

use Illuminate\Support\ServiceProvider;
use App\Core\Security\{SecurityManager, SecurityMonitor};
use App\Core\Auth\AuthenticationService;
use App\Core\CMS\ContentManager;
use App\Core\Template\TemplateManager;
use App\Core\Infrastructure\CoreInfrastructure;
use App\Core\Events\SystemEventService;
use App\Core\Validation\ValidationService;
use App\Core\Audit\AuditService;
use App\Core\Backup\BackupService;

class CoreSystemProvider extends ServiceProvider
{
    protected array $singletons = [
        SecurityManager::class,
        SecurityMonitor::class,
        AuthenticationService::class,
        ContentManager::class,
        TemplateManager::class,
        CoreInfrastructure::class,
        SystemEventService::class,
        ValidationService::class,
        AuditService::class,
        BackupService::class
    ];

    public function register(): void
    {
        $this->registerSingletons();
        $this->registerConfigurations();
        $this->registerMiddleware();
        $this->registerCommands();
    }

    public function boot(): void
    {
        $this->bootSecuritySystem();
        $this->bootInfrastructure();
        $this->bootEventSystem();
        $this->bootBackupSystem();
        $this->bootMonitoring();
        $this->publishAssets();
    }

    protected function registerSingletons(): void
    {
        foreach ($this->singletons as $singleton) {
            $this->app->singleton($singleton, function ($app) use ($singleton) {
                return new $singleton(
                    $app->make(SecurityManager::class),
                    $app->make('cache'),
                    config(strtolower(class_basename($singleton)))
                );
            });
        }
    }

    protected function bootSecuritySystem(): void
    {
        $security = $this->app->make(SecurityManager::class);
        $monitor = $this->app->make(SecurityMonitor::class);

        $security->initialize();
        $monitor->startMonitoring();

        $this->app->terminating(function () use ($security, $monitor) {
            $monitor->shutdown();
            $security->shutdown();
        });
    }

    protected function bootInfrastructure(): void
    {
        $infrastructure = $this->app->make(CoreInfrastructure::class);
        $events = $this->app->make(SystemEventService::class);

        $infrastructure->initialize();

        $this->app->terminating(function () use ($infrastructure) {
            $infrastructure->shutdown();
        });
    }

    protected function bootEventSystem(): void
    {
        $events = $this->app->make(SystemEventService::class);
        $audit = $this->app->make(AuditService::class);

        $events->subscribe('system.*', function ($event) use ($audit) {
            $audit->log($event['type'], $event['data']);
        });
    }

    protected function bootMonitoring(): void
    {
        $monitor = $this->app->make(SecurityMonitor::class);

        $this->app->middleware(function ($request, $next) use ($monitor) {
            $monitor->trackRequest($request);
            $response = $next($request);
            $monitor->trackResponse($response);
            return $response;
        });
    }

    protected function bootBackupSystem(): void 
    {
        $backup = $this->app->make(BackupService::class);
        
        if (!$backup->verifyLastBackup()) {
            $backup->createBackup(true);
        }
    }

    protected function registerConfigurations(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/security.php', 'security');
        $this->mergeConfigFrom(__DIR__.'/../config/cms.php', 'cms');
        $this->mergeConfigFrom(__DIR__.'/../config/audit.php', 'audit');
    }

    protected function registerMiddleware(): void
    {
        $this->app['router']->middlewareGroup('cms', [
            \App\Core\Middleware\SecurityMiddleware::class,
            \App\Core\Middleware\ValidationMiddleware::class,
            \App\Core\Middleware\AuditMiddleware::class
        ]);
    }

    protected function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                \App\Core\Console\SecurityCommand::class,
                \App\Core\Console\BackupCommand::class,
                \App\Core\Console\AuditCommand::class
            ]);
        }
    }
}
