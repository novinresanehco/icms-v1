<?php

namespace App\Core\Providers;

use App\Core\Repositories\Contracts\TemplateRepositoryInterface;
use App\Core\Repositories\TemplateRepository;
use App\Core\Services\Contracts\TemplateServiceInterface;
use App\Core\Services\TemplateService;
use Illuminate\Support\ServiceProvider;

class TemplateServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(TemplateRepositoryInterface::class, TemplateRepository::class);
        $this->app->bind(TemplateServiceInterface::class, TemplateService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');
        $this->loadRoutesFrom(__DIR__ . '/../Routes/template.php');
    }
}
