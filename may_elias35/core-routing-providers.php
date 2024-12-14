<?php

namespace App\Providers;

class CoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SecurityManager::class);
        $this->app->singleton(AuthenticationService::class);
        $this->app->singleton(ContentManager::class);
        $this->app->singleton(TemplateManager::class);
        
        $this->app->singleton(CacheManager::class, function() {
            return new CacheManager(config('cache.default'));
        });
        
        $this->app->singleton(LogManager::class, function() {
            return new LogManager(config('logging.default'));
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');

        $this->app['router']->aliasMiddleware('auth.api', AuthMiddleware::class);
        $this->app['router']->aliasMiddleware('throttle', RateLimitMiddleware::class);
    }
}

// routes/api.php
Route::prefix('api/v1')->group(function() {
    // Auth routes
    Route::post('/auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:5,30');
        
    Route::post('/auth/refresh', [AuthController::class, 'refresh'])
        ->middleware('auth.api');

    // Protected routes
    Route::middleware(['auth.api', 'throttle:60,1'])->group(function() {
        // Content management
        Route::apiResource('content', ContentController::class);
        Route::post('content/{id}/media', [ContentController::class, 'attachMedia']);
        
        // Media management
        Route::post('media/upload', [MediaController::class, 'upload']);
        
        // Categories
        Route::apiResource('categories', CategoryController::class);
        
        // Theme management
        Route::put('themes/{id}/activate', [ThemeController::class, 'activate']);
    });
});

// database/migrations/create_core_tables.php
class CreateCoreTables extends Migration
{
    public function up(): void
    {
        Schema::create('users', function(Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('password_hash');
            $table->string('password_salt');
            $table->string('role');
            $table->string('status');
            $table->timestamps();
        });

        Schema::create('content', function(Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('content');
            $table->string('status');
            $table->foreignId('user_id');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('categories', function(Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->foreignId('parent_id')->nullable();
            $table->timestamps();
        });

        Schema::create('media', function(Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('path');
            $table->string('mime_type');
            $table->integer('size');
            $table->foreignId('user_id');
            $table->morphs('mediable');
            $table->timestamps();
        });

        Schema::create('themes', function(Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('path');
            $table->boolean('active');
            $table->timestamps();
        });

        Schema::create('content_category', function(Blueprint $table) {
            $table->foreignId('content_id');
            $table->foreignId('category_id');
            $table->primary(['content_id', 'category_id']);
        });
    }
}
