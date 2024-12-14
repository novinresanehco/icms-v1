<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Core\Auth\AuthenticationService;
use App\Core\CMS\ContentManager;
use App\Core\Template\TemplateManager;
use App\Core\Infrastructure\CoreInfrastructure;
use App\Core\Security\{SecurityMonitor, SecurityManager};
use App\Core\Exceptions\ExceptionHandler;

class CoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SecurityManager::class, function ($app) {
            return new SecurityManager(
                $app->make('cache'),
                $app->make('log'),
                config('security')
            );
        });

        $this->app->singleton(AuthenticationService::class, function ($app) {
            return new AuthenticationService(
                $app->make(SecurityManager::class),
                $app->make('cache'),
                config('auth')
            );
        });

        $this->app->singleton(ContentManager::class, function ($app) {
            return new ContentManager(
                $app->make(SecurityManager::class),
                $app->make('cache'),
                $app->make('validator')
            );
        });

        $this->app->singleton(TemplateManager::class, function ($app) {
            return new TemplateManager(
                $app->make(SecurityManager::class),
                $app->make('cache'),
                config('templates')
            );
        });

        $this->app->singleton(CoreInfrastructure::class, function ($app) {
            return new CoreInfrastructure(
                $app->make('cache'),
                $app->make(SecurityManager::class),
                $app->make('validator')
            );
        });

        $this->app->singleton(SecurityMonitor::class, function ($app) {
            return new SecurityMonitor(
                $app->make('events'),
                $app->make('alert'),
                config('security.threshold'),
                config('security.patterns')
            );
        });

        $this->app->singleton(ExceptionHandler::class, function ($app) {
            return new ExceptionHandler(
                $app->make(SecurityMonitor::class)
            );
        });

        $this->app->alias(ExceptionHandler::class, 'exception.handler');
    }

    public function boot(): void
    {
        $this->app->make(SecurityMonitor::class)->boot();
        
        $this->app->make(CoreInfrastructure::class)->initialize();
        
        $this->registerErrorHandlers();
        
        $this->registerSecurityMiddleware();
        
        $this->publishConfigurations();
    }

    protected function registerErrorHandlers(): void
    {
        $handler = $this->app->make(ExceptionHandler::class);
        
        set_exception_handler([$handler, 'handle']);
        
        $this->app->make('events')->listen('exception.*', 
            [$handler, 'handle']
        );
    }

    protected function registerSecurityMiddleware(): void
    {
        $this->app['router']->aliasMiddleware('secure', 
            \App\Core\Middleware\SecurityMiddleware::class
        );
        
        $this->app['router']->middlewareGroup('cms', [
            'secure',
            \App\Core\Middleware\CmsAccessMiddleware::class
        ]);
    }

    protected function publishConfigurations(): void
    {
        $this->publishes([
            __DIR__.'/../config/security.php' => config_path('security.php'),
            __DIR__.'/../config/cms.php' => config_path('cms.php'),
            __DIR__.'/../config/templates.php' => config_path('templates.php'),
        ], 'cms-config');
    }
}
