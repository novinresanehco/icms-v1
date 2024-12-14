<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Core\Repositories\Contracts\{
    RepositoryInterface,
    PageRepositoryInterface,
    MediaRepositoryInterface,
    CategoryRepositoryInterface,
    TagRepositoryInterface,
    SettingsRepositoryInterface
};
use App\Core\Repositories\{
    PageRepository,
    MediaRepository,
    CategoryRepository,
    TagRepository,
    SettingsRepository
};

class RepositoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PageRepositoryInterface::class, PageRepository::class);
        $this->app->bind(MediaRepositoryInterface::class, MediaRepository::class);
        $this->app->bind(CategoryRepositoryInterface::class, CategoryRepository::class);
        $this->app->bind(TagRepositoryInterface::class, TagRepository::class);
        $this->app->singleton(SettingsRepositoryInterface::class, SettingsRepository::class);
    }
    
    public function boot(): void
    {
        // Register any boot-time repository setup here
    }
}
