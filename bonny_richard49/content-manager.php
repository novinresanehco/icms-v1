<?php

namespace App\Core\Content;

use App\Core\Security\CoreSecurityManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class ContentManager
{
    private CoreSecurityManager $security;
    private Repository $repository;
    private CacheManager $cache;

    public function createContent(array $data): Content
    {
        return $this->security->executeSecureOperation(
            fn() => $this->storeContent($data),
            [
                'required_permission' => 'content.create',
                'data' => $data
            ]
        );
    }

    public function updateContent(int $id, array $data): Content
    {
        return $this->security->executeSecureOperation(
            fn() => $this->modifyContent($id, $data),
            [
                'required_permission' => 'content.update',
                'data' => $data,
                'content_id' => $id
            ]
        );
    }

    protected function storeContent(array $data): Content
    {
        return DB::transaction(function() use ($data) {
            $content = $this->repository->create($data);
            $this->cache->invalidate('content:'.$content->id);
            return $content;
        });
    }

    protected function modifyContent(int $id, array $data): Content
    {
        return DB::transaction(function() use ($id, $data) {
            $content = $this->repository->update($id, $data);
            $this->cache->invalidate('content:'.$id);
            return $content;
        });
    }

    public function getContent(int $id): ?Content
    {
        return $this->cache->remember(
            'content:'.$id,
            fn() => $this->repository->find($id)
        );
    }
}

class Content
{
    private int $id;
    private string $title;
    private string $content;
    private array $meta;
    private string $status;

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'content' => $this->content,
            'meta' => $this->meta,
            'status' => $this->status
        ];
    }
}

interface Repository
{
    public function find(int $id): ?Content;
    public function create(array $data): Content;
    public function update(int $id, array $data): Content;
    public function delete(int $id): bool;
}

class CacheManager
{
    public function remember(string $key, callable $callback)
    {
        return Cache::remember($key, 3600, $callback);
    }

    public function invalidate(string $key): void
    {
        Cache::forget($key);
    }
}
