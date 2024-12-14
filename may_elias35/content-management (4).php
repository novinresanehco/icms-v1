<?php

namespace App\Core\Content;

class ContentManager implements ContentManagerInterface {

    private SecurityManagerInterface $security;
    private ContentRepository $repository;
    private CacheManager $cache;

    public function store(array $data): Content
    {
        return $this->security->executeProtected(function() use ($data) {
            // Store with validation and caching
            $content = $this->repository->create($data);
            $this->cache->invalidate(['content', $content->id]);
            return $content;
        }, ['action' => 'store_content']);
    }

    public function update(int $id, array $data): Content 
    {
        return $this->security->executeProtected(function() use ($id, $data) {
            $content = $this->repository->findOrFail($id);
            $content->update($data);
            $this->cache->invalidate(['content', $id]);
            return $content;
        }, ['action' => 'update_content', 'id' => $id]);
    }

    public function publish(int $id): bool
    {
        return $this->security->executeProtected(function() use ($id) {
            $content = $this->repository->findOrFail($id);
            $content->publish();
            $this->cache->invalidate(['content', $id]);
            return true;
        }, ['action' => 'publish_content', 'id' => $id]);
    }

    public function find(int $id): ?Content
    {
        return $this->cache->remember(['content', $id], function() use ($id) {
            return $this->repository->find($id);
        });
    }

    public function delete(int $id): bool
    {
        return $this->security->executeProtected(function() use ($id) {
            $content = $this->repository->findOrFail($id);
            $content->delete();
            $this->cache->invalidate(['content', $id]);
            return true;
        }, ['action' => 'delete_content', 'id' => $id]);
    }
}

interface ContentManagerInterface {
    public function store(array $data): Content;
    public function update(int $id, array $data): Content;
    public function publish(int $id): bool;
    public function find(int $id): ?Content;
    public function delete(int $id): bool;
}

class Content extends Model {
    protected $fillable = [
        'title',
        'content',
        'status',
        'author_id',
        'published_at'
    ];

    protected $casts = [
        'published_at' => 'datetime'
    ];

    public function publish(): void {
        $this->update([
            'status' => 'published',
            'published_at' => now()
        ]);
    }

    public function author(): BelongsTo {
        return $this->belongsTo(User::class, 'author_id');
    }
}
