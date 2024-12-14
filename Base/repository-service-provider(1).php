<?php

namespace App\Core\Providers;

use App\Core\Repositories\Contracts\MediaRepositoryInterface;
use App\Core\Repositories\MediaRepository;
use Illuminate\Support\ServiceProvider;

class RepositoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(MediaRepositoryInterface::class, MediaRepository::class);
    }
}
