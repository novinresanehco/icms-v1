<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Core\Repositories\RepositoryInterface;
use App\Repositories\ContentRepository;
use App\Repositories\CategoryRepository;
use App\Repositories\TagRepository;

class RepositoryServiceProvider extends ServiceProvider
{
    protected array $repositories = [
        \App\Core\Contracts\ContentRepositoryInterface::class => \App\Repositories\ContentRepository::class,
        \App\Core\Contracts\CategoryRepositoryInterface::class => \App\Repositories\CategoryRepository::class,
        \App\Core\Contracts\TagRepositoryInterface::class => \App\Repositories\TagRepository::class,
    ];

    public function register(): void
    {
        foreach ($this->repositories as $interface => $implementation) {
            $this->app->bind($interface, $implementation);
        }
    }

    public function provides(): array
    {
        return array_keys($this->repositories);
    }
}
