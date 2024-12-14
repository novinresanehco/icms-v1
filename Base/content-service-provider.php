<?php

namespace App\Providers;

use App\Repositories\ContentRepository;
use App\Repositories\Contracts\ContentRepositoryInterface;
use Illuminate\Support\ServiceProvider;

class ContentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ContentRepositoryInterface::class, ContentRepository::class);
    }

    public function boot(): void
    {
        //
    }
}
