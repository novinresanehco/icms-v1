<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Core\Repositories\Contracts\{
    RepositoryInterface,
    PageRepositoryInterface,
    MediaRepositoryInterface
};
use App\Core\Repositories\{
    PageRepository,
    MediaRepository
};

class RepositoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PageRepositoryInterface::class, PageRepository::class);
        $this->app->bind(MediaRepositoryInterface::class, MediaRepository::class);
    }
}
