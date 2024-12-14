<?php

namespace App\Core\Tag\Providers;

use Illuminate\Support\ServiceProvider;
use App\Core\Tag\Models\Tag;
use App\Core\Tag\Repository\{
    TagRepositoryFactory,
    TagCacheRepository
};
use App\Core\Tag\Contracts\{
    TagReadInterface,
    TagWriteInterface,
    TagCacheInterface,
    TagRelationshipInterface
};

class TagRepositoryServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Register factory
        $this->app->singleton(TagRepositoryFactory::class, function ($app) {
            return new TagRepositoryFactory(
                $app->make(Tag::class),
                $app->make(TagCacheRepository::class)
            );
        });

        // Register repositories through factory
        $this->app->singleton(TagReadInterface::class, function ($app) {
            return $app->make(TagRepositoryFactory::class)->createReadRepository();
        });

        $this->app->singleton(TagWriteInterface::class, function ($app) {
            return $app->make(TagRepositoryFactory::class)->createWriteRepository();
        });

        $this->app->singleton(TagCacheInterface::class, function ($app) {
            return $app->make(TagRepositoryFactory::class)->createCacheRepository();
        });

        $this->app->singleton(TagRelationshipInterface::class, function ($app) {
            return $app->make(TagRepositoryFactory::class)->createRelationshipRepository();
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return [
            TagRepositoryFactory::class,
            TagReadInterface::class,
            TagWriteInterface::class,
            TagCacheInterface::class,
            TagRelationshipInterface::class,
        ];
    }
}
