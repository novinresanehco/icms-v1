<?php

namespace App\Core\Settings\Providers;

use Illuminate\Support\ServiceProvider;
use App\Core\Settings\Services\SettingsService;
use App\Core\Settings\Repositories\SettingRepository;

class SettingsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SettingsService::class);
        $this->app->singleton(SettingRepository::class);

        $this->app->singleton('settings', function ($app) {
            return $app->make(SettingsService::class);
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                \App\Core\Settings\Console\Commands\ListSettings::class,
                \App\Core\Settings\Console\Commands\SetSetting::class,
                \App\Core\Settings\Console\Commands\DeleteSetting::class,
            ]);
        }
    }
}
