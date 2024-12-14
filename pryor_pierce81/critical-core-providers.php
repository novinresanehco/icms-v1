<?php
namespace App\Providers;

class CoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AuthManager::class);
        $this->app->singleton(ContentManager::class);
        $this->app->singleton(TemplateManager::class);
        
        $this->app->singleton(ValidationService::class);
        $this->app->singleton(EncryptionService::class);
        $this->app->singleton(CacheManager::class);
        
        $this->registerRepositories();
    }

    protected function registerRepositories(): void
    {
        $this->app->singleton(UserRepository::class);
        $this->app->singleton(ContentRepository::class);
        $this->app->singleton(TemplateRepository::class);
    }
}

class SecurityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TokenService::class, function() {
            return new TokenService(
                config('app.key'),
                config('auth.token_ttl')
            );
        });

        $this->app->singleton(AccessControl::class);
        $this->app->singleton(AuditLogger::class);
    }

    public function boot(): void
    {
        Gate::before(function(User $user) {
            return $user->isAdmin() ? true : null;
        });
    }
}

return [
    'auth' => [
        'defaults' => [
            'guard' => 'api',
            'passwords' => 'users',
        ],
        'guards' => [
            'api' => [
                'driver' => 'token',
                'provider' => 'users',
            ],
        ],
        'providers' => [
            'users' => [
                'driver' => 'eloquent',
                'model' => App\Models\User::class,
            ],
        ],
        'token_ttl' => env('AUTH_TOKEN_TTL', 3600),
        'rate_limits' => [
            'auth' => [
                'attempts' => 5,
                'decay' => 300,
            ],
        ],
    ],
    
    'cache' => [
        'default' => env('CACHE_DRIVER', 'redis'),
        'stores' => [
            'redis' => [
                'driver' => 'redis',
                'connection' => 'cache',
            ],
        ],
        'prefix' => env('CACHE_PREFIX', 'cms'),
        'ttl' => [
            'content' => 3600,
            'templates' => 86400,
        ],
    ],
    
    'cms' => [
        'content' => [
            'statuses' => ['draft', 'published'],
            'pagination' => [
                'per_page' => 15,
                'max_per_page' => 100,
            ],
        ],
        'templates' => [
            'path' => resource_path('views/templates'),
            'cache' => true,
        ],
        'security' => [
            'password_timeout' => 10800,
            'session_timeout' => 3600,
            'hash_rounds' => 12,
        ],
    ],
];
