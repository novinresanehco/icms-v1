<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Repositories\Contracts\{
    UserRepositoryInterface,
    RoleRepositoryInterface,
    PermissionRepositoryInterface,
    ContentRepositoryInterface,
    MediaRepositoryInterface,
    TemplateRepositoryInterface
};
use App\Repositories\{
    UserRepository,
    RoleRepository,
    PermissionRepository,
    ContentRepository,
    MediaRepository,
    TemplateRepository
};

class RepositoryServiceProvider extends ServiceProvider
{
    public array $bindings = [
        UserRepositoryInterface::class => UserRepository::class,
        RoleRepositoryInterface::class => RoleRepository::class,
        PermissionRepositoryInterface::class => PermissionRepository::class,
        ContentRepositoryInterface::class => ContentRepository::class,
        MediaRepositoryInterface::class => MediaRepository::class,
        TemplateRepositoryInterface::class => TemplateRepository::class,
    ];

    public function register(): void
    {
        foreach ($this->bindings as $interface => $implementation) {
            $this->app->bind($interface, $implementation);
        }
    }
}
