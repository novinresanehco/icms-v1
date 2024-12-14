<?php

namespace App\Core;

class MediaManager
{
    private SecurityManager $security;
    private string $basePath;

    public function __construct(SecurityManager $security)
    {
        $this->security = $security;
        $this->basePath = storage_path('app/media');
    }

    public function store(UploadedFile $file): array
    {
        return $this->security->executeCriticalOperation(function() use ($file) {
            $hash = hash_file('sha256', $file->path());
            $path = $file->store('media');

            return DB::transaction(function() use ($file, $path, $hash) {
                return DB::table('media')->insertGetId([
                    'path' => $path,
                    'hash' => $hash,
                    'mime' => $file->getMimeType(),
                    'size' => $file->getSize(),
                    'created_at' => now()
                ]);
            });
        }, ['action' => 'media.store']);
    }

    public function get(int $id): ?array
    {
        return DB::table('media')->find($id);
    }

    public function delete(int $id): bool
    {
        return $this->security->executeCriticalOperation(function() use ($id) {
            return DB::transaction(function() use ($id) {
                $media = DB::table('media')->find($id);
                if ($media) {
                    Storage::delete($media->path);
                    return DB::table('media')->delete($id);
                }
                return false;
            });
        }, ['action' => 'media.delete']);
    }
}

class CategoryManager
{
    private SecurityManager $security;
    private CacheManager $cache;

    public function __construct(SecurityManager $security, CacheManager $cache)
    {
        $this->security = $security;
        $this->cache = $cache;
    }

    public function create(array $data): int
    {
        return $this->security->executeCriticalOperation(function() use ($data) {
            $id = DB::transaction(function() use ($data) {
                return DB::table('categories')->insertGetId([
                    'name' => $data['name'],
                    'slug' => Str::slug($data['name']),
                    'parent_id' => $data['parent_id'] ?? null,
                    'created_at' => now()
                ]);
            });

            $this->cache->invalidate(['categories', 'category_tree']);
            return $id;
        }, ['action' => 'category.create']);
    }

    public function getTree(): array
    {
        return $this->cache->remember('category_tree', function() {
            return $this->buildTree(DB::table('categories')->get());
        });
    }

    private function buildTree($categories, $parentId = null): array
    {
        $branch = [];
        
        foreach ($categories as $category) {
            if ($category->parent_id === $parentId) {
                $children = $this->buildTree($categories, $category->id);
                if ($children) {
                    $category->children = $children;
                }
                $branch[] = $category;
            }
        }
        
        return $branch;
    }
}

class ContentTypeManager
{
    private SecurityManager $security;
    private array $registeredTypes = [];

    public function register(string $type, array $config): void
    {
        $this->security->executeCriticalOperation(function() use ($type, $config) {
            $this->validateConfig($config);
            $this->registeredTypes[$type] = $config;
        }, ['action' => 'contentType.register']);
    }

    public function getType(string $type): ?array
    {
        return $this->registeredTypes[$type] ?? null;
    }

    private function validateConfig(array $config): void
    {
        $required = ['fields', 'validations', 'permissions'];
        foreach ($required as $key) {
            if (!isset($config[$key])) {
                throw new InvalidArgumentException("Missing required config key: {$key}");
            }
        }
    }
}

class CoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SecurityManager::class);
        $this->app->singleton(MediaManager::class);
        $this->app->singleton(CategoryManager::class);
        $this->app->singleton(ContentTypeManager::class);
    }

    public function boot(): void
    {
        $this->loadMigrations();
        $this->registerPolicies();
    }

    private function loadMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }

    private function registerPolicies(): void
    {
        Gate::define('manage-media', fn(User $user) => 
            $user->hasPermission('media.manage')
        );

        Gate::define('manage-categories', fn(User $user) => 
            $user->hasPermission('categories.manage')
        );
    }
}

// Critical migrations
Schema::create('media', function (Blueprint $table) {
    $table->id();
    $table->string('path');
    $table->string('hash', 64);
    $table->string('mime');
    $table->integer('size');
    $table->timestamps();
    $table->index('hash');
});

Schema::create('categories', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->string('slug')->unique();
    $table->unsignedBigInteger('parent_id')->nullable();
    $table->timestamps();
    $table->foreign('parent_id')
          ->references('id')
          ->on('categories')
          ->onDelete('cascade');
    $table->index(['parent_id', 'slug']);
});

Schema::create('content_categories', function (Blueprint $table) {
    $table->unsignedBigInteger('content_id');
    $table->unsignedBigInteger('category_id');
    $table->primary(['content_id', 'category_id']);
    $table->foreign('content_id')
          ->references('id')
          ->on('content')
          ->onDelete('cascade');
    $table->foreign('category_id')
          ->references('id')
          ->on('categories')
          ->onDelete('cascade');
});
