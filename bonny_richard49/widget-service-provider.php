// app/Providers/WidgetServiceProvider.php
<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Core\Widget\Contracts\{
    WidgetFactoryInterface,
    WidgetRepositoryInterface,
    WidgetProcessorInterface,
    WidgetRendererInterface
};
use App\Core\Widget\{
    Factories\WidgetFactory,
    Repositories\WidgetRepository,
    Processors\WidgetProcessor,
    Renderers\WidgetRenderer
};

class WidgetServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../../config/widgets.php', 'widgets'
        );

        $this->app->singleton(WidgetFactoryInterface::class, WidgetFactory::class);
        $this->app->singleton(WidgetRepositoryInterface::class, WidgetRepository::class);
        $this->app->singleton(WidgetProcessorInterface::class, WidgetProcessor::class);
        $this->app->singleton(WidgetRendererInterface::class, WidgetRenderer::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../../config/widgets.php' => config_path('widgets.php'),
        ], 'widgets-config');

        $this->loadViewsFrom(
            config('widgets.views.path'), 
            config('widgets.views.namespace')
        );

        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        $this->registerWidgetTypes();
    }

    private function registerWidgetTypes(): void
    {
        $typeResolver = $this->app->make(WidgetTypeResolver::class);

        foreach (config('widgets.types', []) as $type => $class) {
            $typeResolver->register($type, $class);
        }
    }
}