<?php

namespace App\Core\CMS;

use App\Core\Security\SecurityManager;
use App\Core\Exceptions\CMSException;
use Illuminate\Support\Facades\{Cache, DB};

class ContentManager
{
    private SecurityManager $security;
    private ContentRepository $repository;
    private CacheManager $cache;

    public function __construct(
        SecurityManager $security,
        ContentRepository $repository,
        CacheManager $cache
    ) {
        $this->security = $security;
        $this->repository = $repository;
        $this->cache = $cache;
    }

    public function store(array $data): Content
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->repository->create($data),
            ['action' => 'store', 'data' => $data]
        );
    }

    public function update(int $id, array $data): Content
    {
        return $this->security->executeCriticalOperation(
            function() use ($id, $data) {
                $content = $this->repository->update($id, $data);
                $this->cache->invalidate(["content.{$id}"]);
                return $content;
            },
            ['action' => 'update', 'id' => $id, 'data' => $data]
        );
    }

    public function delete(int $id): bool
    {
        return $this->security->executeCriticalOperation(
            function() use ($id) {
                $result = $this->repository->delete($id);
                $this->cache->invalidate(["content.{$id}"]);
                return $result;
            },
            ['action' => 'delete', 'id' => $id]
        );
    }

    public function find(int $id): ?Content
    {
        return $this->cache->remember(
            "content.{$id}",
            fn() => $this->repository->find($id)
        );
    }
}

class ContentRepository
{
    private DB $db;

    public function create(array $data): Content
    {
        return DB::transaction(function() use ($data) {
            $content = Content::create($this->validate($data));
            $this->processMedia($content, $data['media'] ?? []);
            $this->processCategories($content, $data['categories'] ?? []);
            return $content->fresh();
        });
    }

    public function update(int $id, array $data): Content
    {
        return DB::transaction(function() use ($id, $data) {
            $content = Content::findOrFail($id);
            $content->update($this->validate($data));
            
            if (isset($data['media'])) {
                $this->processMedia($content, $data['media']);
            }
            
            if (isset($data['categories'])) {
                $this->processCategories($content, $data['categories']);
            }
            
            return $content->fresh();
        });
    }

    public function delete(int $id): bool
    {
        return DB::transaction(function() use ($id) {
            $content = Content::findOrFail($id);
            $content->media()->delete();
            $content->categories()->detach();
            return $content->delete();
        });
    }

    public function find(int $id): ?Content
    {
        return Content::with(['media', 'categories'])->find($id);
    }

    private function validate(array $data): array
    {
        $rules = [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'status' => 'required|in:draft,published',
            'media.*' => 'nullable|exists:media,id',
            'categories.*' => 'nullable|exists:categories,id'
        ];

        return validator($data, $rules)->validate();
    }

    private function processMedia(Content $content, array $mediaIds): void
    {
        $content->media()->sync($mediaIds);
    }

    private function processCategories(Content $content, array $categoryIds): void
    {
        $content->categories()->sync($categoryIds);
    }
}

class CacheManager
{
    public function remember(string $key, callable $callback, ?int $ttl = 3600)
    {
        return Cache::remember($key, $ttl, $callback);
    }

    public function invalidate(array|string $keys): void
    {
        foreach ((array)$keys as $key) {
            Cache::forget($key);
        }
    }
}

class Content extends Model
{
    protected $fillable = [
        'title',
        'content',
        'status',
        'user_id',
        'published_at'
    ];

    protected $casts = [
        'published_at' => 'datetime'
    ];

    public function media()
    {
        return $this->belongsToMany(Media::class);
    }

    public function categories()
    {
        return $this->belongsToMany(Category::class);
    }
}

class Media extends Model
{
    protected $fillable = [
        'type',
        'path',
        'mime_type',
        'size'
    ];

    public function contents()
    {
        return $this->belongsToMany(Content::class);
    }
}

class Category extends Model 
{
    protected $fillable = [
        'name',
        'slug',
        'parent_id'
    ];

    public function contents()
    {
        return $this->belongsToMany(Content::class);
    }

    public function parent()
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Category::class, 'parent_id');
    }
}
