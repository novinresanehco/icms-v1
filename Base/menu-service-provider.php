<?php

namespace App\Providers;

use App\Repositories\Contracts\MenuRepositoryInterface;
use App\Repositories\MenuRepository;
use Illuminate\Support\ServiceProvider;

class MenuServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(MenuRepositoryInterface::class, MenuRepository::class);
    }

    public function boot(): void
    {
        // Register any boot-time functionality here
    }
}
