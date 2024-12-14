<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class TemplateServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TemplateEngine::class, function ($app) {
            return new TemplateEngine(
                $app->make(SecurityValidator::class),
                $app->make(CacheManager::class),
                $app->make(ContentRenderer::class)
            );
        });

        $this->app->singleton(ContentDisplayManager::class, function ($app) {
            return new ContentDisplayManager(
                $app->make(SecurityValidator::class),
                $app->make(TemplateEngine::class),
                $app->make(CacheManager::class)
            );
        });

        $this->app->singleton(MediaGalleryProcessor::class, function ($app) {
            return new MediaGalleryProcessor(
                $app->make(SecurityValidator::class),
                $app->make(ImageProcessor::class),
                $app->make(CacheManager::class)
            );
        });

        $this->app->singleton(UIComponentRegistry::class, function ($app) {
            return new UIComponentRegistry(
                $app->make(SecurityValidator::class)
            );
        });
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views/templates', 'templates');
        $this->mergeConfigFrom(__DIR__.'/../config/templates.php', 'templates');

        $this->publishes([
            __DIR__.'/../config/templates.php' => config_path('templates.php'),
            __DIR__.'/../resources/views/templates' => resource_path('views/templates'),
        ], 'templates');
    }
}

class TemplateCacheServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TemplateCache::class, function ($app) {
            return new TemplateCache(
                $app->make(CacheManager::class),
                $app->make(SecurityValidator::class),
                config('templates.cache')
            );
        });

        $this->app->singleton(TemplateCacheManager::class, function ($app) {
            return new TemplateCacheManager(
                $app->make(TemplateCache::class),
                $app->make(TemplateCacheWarmer::class),
                $app->make(TemplateCacheInvalidator::class),
                $app->make(TemplateOptimizer::class)
            );
        });
    }
}

class TemplateSecurityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SecurityValidator::class, function ($app) {
            return new SecurityValidator();
        });

        $this->app->singleton(ValidationManager::class, function ($app) {
            return new ValidationManager(
                $app->make(SecurityValidator::class)
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/template-security.php' => config_path('template-security.php'),
        ], 'template-security');
    }
}
