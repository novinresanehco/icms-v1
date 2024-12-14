<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Core\Repositories\Contracts\{
    PageRepositoryInterface,
    CategoryRepositoryInterface,
    TagRepositoryInterface
};
use App\Core\Repositories\{
    PageRepository,
    CategoryRepository,
    TagRepository
};
use App\Core\Repositories\Decorators\{
    CacheablePageRepository,
    EventAwareRepository
};

class RepositoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Register base repositories
        $this->app->bind(PageRepositoryInterface::class, function ($app) {
            $repository = new PageRepository($app->make('App\Models\Page'));
            
            // Wrap with event awareness
            $repository = new EventAwareRepository($repository);
            
            // Wrap with cache layer
            if (config('cms.enable_repository_cache', true)) {
                $repository = new CacheablePageRepository($repository);
            }
            
            return $repository;
        });

        // Similar bindings for other repositories...
    }

    public function boot(): void
    {
        // Register repository event listeners
        $this->registerRepositoryEventListeners();
    }

    protected function registerRepositoryEventListeners(): void
    {
        $events = [
            'ModelCreated' => 'handleModelCreated',
            'ModelUpdated' => 'handleModelUpdated',
            'ModelDeleted' => 'handleModelDeleted',
            'ModelRestored' => 'handleModelRestored'
        ];

        foreach ($events as $event => $handler) {
            $eventClass = "App\\Core\\Repositories\\Events\\{$event}";
            $handlerClass = "App\\Listeners\\Repository\\{$handler}";
            
            if (class_exists($eventClass) && class_exists($handlerClass)) {
                \Event::listen($eventClass, $handlerClass);
            }
        }
    }
}
