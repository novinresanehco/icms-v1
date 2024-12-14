<?php

namespace App\Providers;

use App\Services\WidgetManager;
use App\Widgets\HtmlWidget;
use Illuminate\Support\ServiceProvider;

class WidgetServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(WidgetManager::class);
    }

    public function boot(WidgetManager $widgetManager): void
    {
        // Register core widgets
        $widgetManager->registerWidget('html', HtmlWidget::class);
        
        // Add Blade directive for rendering widget areas
        \Blade::directive('widgetArea', function ($expression) {
            return "<?php echo app(App\Services\WidgetManager::class)->renderArea($expression); ?>";
        });
    }
}
