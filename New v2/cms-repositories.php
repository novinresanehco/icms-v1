<?php

namespace App\Core\Repositories;

use App\Models\{Content, Media};
use App\Core\Security\SecurityManager;
use Illuminate\Support\Facades\{Cache, DB};
use App\Core\Interfaces\{ContentRepositoryInterface, MediaRepositoryInterface};

class ContentRepository implements ContentRepositoryInterface
{
    protected Content $model;
    protected SecurityManager $security;
    protected array $cacheConfig;

    public function __construct(Content $model, SecurityManager $security) 
    {
        $this->model = $model;
        $this->security = $security;
        $this->cacheConfig = config('cache.content');
    }

    public function findOrFail(int $id): Content
    {
        return Cache::remember(
            "content.{$id}",
            $this->cacheConfig['ttl'],
            fn() => $this->model->findOrFail($id)
        );
    }

    public function create(array $data): Content
    {
        return DB::transaction(function() use ($data) {
            $content = $this->model->create($data);
            $content->checksum = $this->security->generateChecksum($data);
            $content->save();
            return $content;
        });
    }

    public function update(int $id, array $data): Content
    {
        return DB::transaction(function() use ($id, $data) {
            $content = $this->findOrFail($id);
            $content->update($data);
            $content->checksum = $this->security->generateChecksum($data);
            $content->save();
            return $content;
        });
    }

    public function delete(int $id): bool
    {
        return DB::transaction(function() use ($id) {
            $content = $this->findOrFail($id);
            return $content->delete();
        });
    }

    public function publish(int $id): Content
    {
        return DB::transaction(function() use ($id) {
            $content = $this->findOrFail($id);
            $content->published_at = now();
            $content->save();
            return $content;
        });
    }
}

class MediaRepository implements MediaRepositoryInterface
{
    protected Media $model;
    protected SecurityManager $security;
    protected array $cacheConfig;

    public function __construct(Media $model, SecurityManager $security)
    {
        $this->model = $model;
        $this->security = $security;
        $this->cacheConfig = config('cache.media');
    }

    public function findOrFail(int $id): Media
    {
        return Cache::remember(
            "media.{$id}",
            $this->cacheConfig['ttl'],
            fn() => $this->model->findOrFail($id)
        );
    }

    public function store(array $data): Media
    {
        return DB::transaction(function() use ($data) {
            $media = $this->model->create($data);
            $media->checksum = $this->security->generateChecksum($data);
            $media->save();
            return $media;
        });
    }

    public function delete(int $id): bool
    {
        return DB::transaction(function() use ($id) {
            $media = $this->findOrFail($id);
            return $media->delete();
        });
    }
}