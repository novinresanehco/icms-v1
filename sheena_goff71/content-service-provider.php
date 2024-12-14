<?php

namespace App\Core\Content\Providers;

use Illuminate\Support\ServiceProvider;
use App\Core\Content\Repositories\ContentRepository;
use App\Core\Content\Services\{
    ContentService,
    ContentCacheService,
    ContentValidator
};
use App\Core\Content\Http\Controllers\ContentController;

class ContentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ContentRepository::class);
        $this->app->singleton(ContentService::class);
        $this->app->singleton(ContentCacheService::class);
        $this->app->singleton(ContentValidator::class);

        $this->mergeConfigFrom(
            __DIR__ . '/../Config/content.php',
            'content'
        );
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');
        $this->loadRoutesFrom(__DIR__ . '/../Routes/api.php');

        $this->publishes([
            __DIR__ . '/../Config/content.php' => config_path('content.php'),
        ], 'content-config');

        $this->publishes([
            __DIR__ . '/../Database/Migrations/' => database_path('migrations'),
        ], 'content-migrations');
    }
}
