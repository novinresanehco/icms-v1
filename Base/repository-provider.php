<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Core\Repositories\RepositoryInterface;
use App\Repositories\{
    ContentRepository,
    UserRepository,
    MediaRepository
};

class RepositoryServiceProvider extends ServiceProvider
{
    protected $repositories = [
        'content' => ContentRepository::class,
        'user' => UserRepository::class,
        'media' => MediaRepository::class
    ];

    public function register(): void
    {
        foreach ($this->repositories as $key => $repository) {
            $this->app->bind("App\\Repositories\\Contracts\\{$key}RepositoryInterface", $repository);
        }
    }
}
