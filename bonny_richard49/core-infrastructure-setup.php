<?php

namespace App\Core\Database\Migrations;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCoreSchemas extends Migration
{
    public function up()
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('username')->unique();
            $table->string('password');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained()->onDelete('cascade');
            $table->foreignId('permission_id')->constrained()->onDelete('cascade');
            $table->primary(['role_id', 'permission_id']);
        });

        Schema::create('user_roles', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('role_id')->constrained()->onDelete('cascade');
            $table->primary(['user_id', 'role_id']);
        });

        Schema::create('content', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('content');
            $table->string('status');
            $table->json('meta');
            $table->foreignId('author_id')->constrained('users');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->string('filename');
            $table->string('path');
            $table->string('mime_type');
            $table->integer('size');
            $table->foreignId('uploaded_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('media');
        Schema::dropIfExists('content');
        Schema::dropIfExists('user_roles');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('users');
    }
}

namespace App\Core\Providers;

use Illuminate\Support\ServiceProvider;

class CoreServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->singleton(CoreSecurityManager::class);
        $this->app->singleton(CacheSystem::class);
        $this->app->singleton(AuthenticationSystem::class);
        $this->app->singleton(ContentManager::class);
        $this->app->singleton(TemplateManager::class);
    }

    public function boot()
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->loadViewsFrom(__DIR__.'/../Views', 'core');
        
        $this->publishes([
            __DIR__.'/../config/core.php' => config_path('core.php'),
        ], 'core-config');

        $this->registerMiddleware();
    }

    protected function registerMiddleware()
    {
        $router = $this->app['router'];
        
        $router->aliasMiddleware('auth.cms', \App\Core\Middleware\AuthenticateUser::class);
        $router->aliasMiddleware('permission', \App\Core\Middleware\CheckPermission::class);
    }
}

namespace App\Core\Middleware;

use Closure;
use App\Core\Security\CoreSecurityManager;

class AuthenticateUser
{
    private CoreSecurityManager $security;

    public function handle($request, Closure $next)
    {
        $token = $request->bearerToken();

        if (!$token || !$this->security->validateToken($token)) {
            throw new UnauthorizedException('Invalid or expired token');
        }

        return $next($request);
    }
}

class CheckPermission
{
    private CoreSecurityManager $security;

    public function handle($request, Closure $next, string $permission)
    {
        if (!$this->security->hasPermission($permission)) {
            throw new UnauthorizedException('Insufficient permissions');
        }

        return $next($request);
    }
}
