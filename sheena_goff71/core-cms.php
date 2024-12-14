<?php

namespace App\Core\CMS;

use Illuminate\Support\Facades\{DB, Cache};
use App\Core\Security\SecurityManager;

class ContentManager
{
    private SecurityManager $security;
    private ContentRepository $repository;
    private MediaManager $media;
    private CacheManager $cache;

    public function __construct(
        SecurityManager $security,
        ContentRepository $repository,
        MediaManager $media,
        CacheManager $cache
    ) {
        $this->security = $security;
        $this->repository = $repository;
        $this->media = $media;
        $this->cache = $cache;
    }

    public function create(array $data, array $media = []): Content
    {
        return $this->security->executeCriticalOperation(fn() => 
            DB::transaction(function() use ($data, $media) {
                $content = $this->repository->create($data);
                
                if (!empty($media)) {
                    $this->media->attachToContent($content->id, $media);
                }
                
                $this->cache->invalidateContentCache($content->id);
                return $content;
            }), 
            ['action' => 'content_create']
        );
    }

    public function update(int $id, array $data, array $media = []): Content
    {
        return $this->security->executeCriticalOperation(fn() =>
            DB::transaction(function() use ($id, $data, $media) {
                $content = $this->repository->update($id, $data);
                
                if (!empty($media)) {
                    $this->media->syncWithContent($content->id, $media);
                }
                
                $this->cache->invalidateContentCache($id);
                return $content;
            }),
            ['action' => 'content_update', 'id' => $id]
        );
    }

    public function get(int $id): ?Content
    {
        return $this->cache->remember("content.$id", fn() =>
            $this->repository->find($id)
        );
    }

    public function delete(int $id): bool
    {
        return $this->security->executeCriticalOperation(fn() =>
            DB::transaction(function() use ($id) {
                $this->media->detachFromContent($id);
                $result = $this->repository->delete($id);
                $this->cache->invalidateContentCache($id);
                return $result;
            }),
            ['action' => 'content_delete', 'id' => $id]
        );
    }
}

class MediaManager
{
    private MediaRepository $repository;
    private string $basePath;

    public function store(array $file): Media
    {
        $path = $file['file']->store('media', 'public');
        return $this->repository->create([
            'path' => $path,
            'type' => $file['file']->getMimeType(),
            'size' => $file['file']->getSize(),
            'title' => $file['title'] ?? null
        ]);
    }

    public function attachToContent(int $contentId, array $mediaIds): void
    {
        $this->repository->attachToContent($contentId, $mediaIds);
    }

    public function syncWithContent(int $contentId, array $mediaIds): void
    {
        $this->repository->syncWithContent($contentId, $mediaIds);
    }

    public function detachFromContent(int $contentId): void
    {
        $this->repository->detachFromContent($contentId);
    }
}

class ContentRepository
{
    public function create(array $data): Content
    {
        return Content::create($data);
    }

    public function update(int $id, array $data): Content
    {
        $content = Content::findOrFail($id);
        $content->update($data);
        return $content;
    }

    public function find(int $id): ?Content
    {
        return Content::with(['media', 'categories'])->find($id);
    }

    public function delete(int $id): bool
    {
        return Content::destroy($id) > 0;
    }
}

class CacheManager
{
    public function remember(string $key, callable $callback)
    {
        return Cache::remember($key, config('cache.ttl'), $callback);
    }

    public function invalidateContentCache(int $contentId): void
    {
        Cache::forget("content.$contentId");
    }
}

class Content extends Model
{
    protected $fillable = ['title', 'content', 'status', 'author_id', 'published_at'];
    
    protected $casts = [
        'published_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
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
    protected $fillable = ['path', 'type', 'size', 'title'];
}
