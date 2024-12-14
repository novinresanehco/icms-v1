<?php

namespace App\Core\Media\Providers;

use Illuminate\Support\ServiceProvider;
use App\Core\Media\Repositories\MediaRepository;
use App\Core\Media\Services\{
    MediaHandlerService,
    MediaCacheService
};
use App\Core\Media\Services\Processors\{
    ImageProcessor,
    VideoProcessor,
    DocumentProcessor
};

class MediaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MediaRepository::class);
        $this->app->singleton(MediaHandlerService::class);
        $this->app->singleton(MediaCacheService::class);
        
        $this->app->singleton(ImageProcessor::class);
        $this->app->singleton(VideoProcessor::class);
        $this->app->singleton(DocumentProcessor::class);

        $this->mergeConfigFrom(
            __DIR__ . '/../Config/media.php',
            'media'
        );
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');
        $this->loadRoutesFrom(__DIR__ . '/../Routes/api.php');
        
        $this->publishes([
            __DIR__ . '/../Config/media.php' => config_path('media.php'),
        ], 'media-config');
        
        $this->publishes([
            __DIR__ . '/../Database/Migrations/' => database_path('migrations')
        ], 'media-migrations');

        $this->registerCommands();
    }

    protected function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                \App\Core\Media\Console\Commands\CleanUnusedMedia::class,
                \App\Core\Media\Console\Commands\RegenerateMediaVariants::class,
            ]);
        }
    }
}
