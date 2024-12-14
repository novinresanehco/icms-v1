<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Core\Contracts\{
    ContentRepositoryInterface,
    CategoryRepositoryInterface,
    MediaRepositoryInterface,
    TagRepositoryInterface,
    UserRepositoryInterface
};
use App\Repositories\{
    ContentRepository,
    CategoryRepository,
    MediaRepository,
    TagRepository,
    UserRepository
};

class RepositoryServiceProvider extends ServiceProvider
{
    protected array $repositories = [
        ContentRepositoryInterface::class => ContentRepository::class,
        CategoryRepositoryInterface::class => CategoryRepository::class,
        MediaRepositoryInterface::class => MediaRepository::class,
        TagRepositoryInterface::class => TagRepository::class,
        UserRepositoryInterface::class => UserRepository::class,
    ];

    public function register(): void
    {
        foreach ($this->repositories as $interface => $implementation) {
            $this->app->bind($interface, $implementation);
        }
    }

    public function boot(): void
    {
        // Register any repository event listeners or observers
        $this->registerRepositoryEvents();
    }

    protected function registerRepositoryEvents(): void
    {
        // Register repository-specific event listeners
        $this->app['events']->listen(
            'repository.entity.created',
            [RepositoryEventSubscriber::class, 'handleEntityCreated']
        );

        $this->app['events']->listen(
            'repository.entity.updated',
            [RepositoryEventSubscriber::class, 'handleEntityUpdated']
        );

        $this->app['events']->listen(
            'repository.entity.deleted',
            [RepositoryEventSubscriber::class, 'handleEntityDeleted']
        );
    }
}
