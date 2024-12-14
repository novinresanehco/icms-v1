<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Core\Repositories\RepositoryEventDispatcher;
use App\Core\Cache\CacheManager;
use App\Repositories\ContentRepository;

class RepositoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RepositoryEventDispatcher::class);
        
        $this->app->singleton(ContentRepository::class, function ($app) {
            return new ContentRepository(
                $app->make(CacheManager::class)
            );
        });
    }

    public function provides(): array
    {
        return [
            RepositoryEventDispatcher::class,
            ContentRepository::class,
        ];
    }
}
