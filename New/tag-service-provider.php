<?php

namespace App\Core\Tagging;

use Illuminate\Support\ServiceProvider;

class TagServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TagManagerInterface::class, TagManager::class);
        $this->app->singleton(TagRepositoryInterface::class, TagRepository::class);

        $this->app->when(TagManager::class)
            ->needs(MetricsCollector::class)
            ->give(function() {
                return new MetricsCollector('tagging');
            });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/Database/Migrations');
    }
}
