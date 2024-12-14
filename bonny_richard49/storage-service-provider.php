<?php

namespace App\Providers;

use App\Core\System\StorageService;
use App\Core\Interfaces\StorageServiceInterface;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

class StorageServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(StorageServiceInterface::class, function ($app) {
            return new StorageService(
                $app->make(LoggerInterface::class),
                config('filesystems.default')
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/filesystems.php' => config_path('filesystems.php'),
        ], 'config');

        $this->mergeConfigFrom(
            __DIR__.'/../config/filesystems.php',
            'filesystems'
        );
    }
}
