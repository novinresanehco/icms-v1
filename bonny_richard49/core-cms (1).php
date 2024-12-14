<?php

namespace App\Core\CMS;

class ContentManager
{
    private $repository;
    private $cache;

    public function create(array $data): Content
    {
        DB::beginTransaction();
        try {
            $content = $this->repository->create($data);
            $this->cache->put($this->getCacheKey($content->id), $content);
            DB::commit();
            return $content;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function update(int $id, array $data): Content
    {
        DB::beginTransaction();
        try {
            $content = $this->repository->update($id, $data);
            $this->cache->put($this->getCacheKey($id), $content);
            DB::commit();
            return $content;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function find(int $id): ?Content
    {
        return $this->cache->remember(
            $this->getCacheKey($id),
            3600,
            fn() => $this->repository->find($id)
        );
    }

    private function getCacheKey(int $id): string
    {
        return "content:{$id}";
    }
}

class Content
{
    public $id;
    public $title;
    public $body;
    public $status;
    public $userId;
    public $createdAt;
    public $updatedAt;
}

class MediaManager
{
    private $storage;

    public function store(UploadedFile $file): Media
    {
        $path = $file->store('media');
        return new Media([
            'path' => $path,
            'filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize()
        ]);
    }

    public function delete(Media $media): void
    {
        Storage::delete($media->path);
        $media->delete();
    }
}

class Media
{
    public $id;
    public $path;
    public $filename;
    public $mimeType;
    public $size;
}

class CategoryManager
{
    private $repository;

    public function create(array $data): Category
    {
        return $this->repository->create($data);
    }

    public function getTree(): array
    {
        return $this->repository->getTree();
    }
}

class Category
{
    public $id;
    public $name;
    public $slug;
    public $parentId;
}
