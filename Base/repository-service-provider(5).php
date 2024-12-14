<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Core\Repositories\RepositoryInterface;
use App\Repositories\ContentRepository;
use App\Repositories\UserRepository;

class RepositoryServiceProvider extends ServiceProvider
{
    public array $bindings = [
        'App\Contracts\Repositories\ContentRepositoryInterface' => ContentRepository::class,
        'App\Contracts\Repositories\UserRepositoryInterface' => UserRepository::class,
    ];

    public function register(): void
    {
        // Register repositories
        $this->app->singleton(ContentRepository::class);
        $this->app->singleton(UserRepository::class);
    }

    public function provides(): array
    {
        return [
            ContentRepository::class,
            UserRepository::class,
        ];
    }
}
