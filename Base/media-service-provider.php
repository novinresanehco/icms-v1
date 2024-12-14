<?php

namespace App\Core\Providers;

use App\Core\Services\Contracts\MediaServiceInterface;
use App\Core\Services\MediaService;
use App\Core\Models\Media;
use App\Core\Policies\MediaPolicy;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;

class MediaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(MediaServiceInterface::class, MediaService::class);
    }

    public function boot(): void
    {
        Gate::policy(Media::class, MediaPolicy::class);
        
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');
    }
}
