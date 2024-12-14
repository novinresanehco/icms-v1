<?php

namespace App\Providers;

use App\Core\Modules\ModuleManager;
use App\Core\Modules\ModuleRegistry;
use Illuminate\Support\ServiceProvider;

class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ModuleRegistry::class);
        
        $this->app->singleton(ModuleManager::class, function($app) {
            return new ModuleManager(
                $app->make(ModuleRegistry::class)
            );
        });
    }

    public function boot(ModuleManager $moduleManager): void
    {
        $modules = config('modules', []);
        
        foreach ($modules as $module) {
            $moduleManager->addConfiguration($module);
        }

        $moduleManager->loadModules();
    }
}
