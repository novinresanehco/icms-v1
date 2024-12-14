<?php

namespace App\Core\Providers;

use Illuminate\Support\ServiceProvider;
use App\Core\Models\Category;
use App\Core\Policies\CategoryPolicy;
use Illuminate\Support\Facades\Gate;

class CategoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CategoryService::class, function ($app) {
            return new CategoryService(
                $app->make(CategoryRepositoryInterface::class)
            );
        });
    }

    public function boot(): void
    {
        Gate::policy(Category::class, CategoryPolicy::class);

        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');
        $this->loadRoutesFrom(__DIR__ . '/../Routes/api.php');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../Config/category.php' => config_path('category.php'),
            ], 'category-config');
        }
    }
}
