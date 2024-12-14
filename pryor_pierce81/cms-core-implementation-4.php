<?php

namespace App\Core\CMS;

use App\Core\Security\CoreSecurityManager;

class ContentManager 
{
    private CoreSecurityManager $security;
    private ContentRepository $repository;
    private CacheManager $cache;

    public function store(array $data): Content 
    {
        return $this->security->executeSecureOperation(function() use ($data) {
            $validated = $this->validateContent($data);
            $content = $this->repository->create($validated);
            $this->cache->remember("content.{$content->id}", $content);
            return $content;
        });
    }

    public function update(int $id, array $data): Content 
    {
        return $this->security->executeSecureOperation(function() use ($id, $data) {
            $validated = $this->validateContent($data);
            $content = $this->repository->update($id, $validated);
            $this->cache->forget("content.{$id}");
            $this->cache->remember("content.{$id}", $content);
            return $content;
        });
    }

    private function validateContent(array $data): array 
    {
        $rules = [
            'title' => 'required|max:255',
            'content' => 'required',
            'status' => 'required|in:draft,published'
        ];

        return validator($data, $rules)->validate();
    }
}

class ContentRepository 
{
    public function create(array $data): Content 
    {
        return DB::transaction(function() use ($data) {
            $content = Content::create($data);
            $this->createRevision($content);
            return $content;
        });
    }

    public function update(int $id, array $data): Content 
    {
        return DB::transaction(function() use ($id, $data) {
            $content = Content::findOrFail($id);
            $content->update($data);
            $this->createRevision($content);
            return $content;
        });
    }

    private function createRevision(Content $content): void 
    {
        ContentRevision::create([
            'content_id' => $content->id,
            'data' => $content->toArray(),
            'created_by' => auth()->id()
        ]);
    }
}

class CacheManager 
{
    private const TTL = 3600;

    public function remember(string $key, $value): void 
    {
        Cache::put($key, $value, self::TTL);
    }

    public function forget(string $key): void 
    {
        Cache::forget($key);
    }

    public function get(string $key) 
    {
        return Cache::get($key);
    }
}

class MediaManager 
{
    private CoreSecurityManager $security;
    private StorageService $storage;

    public function store(UploadedFile $file): Media 
    {
        return $this->security->executeSecureOperation(function() use ($file) {
            $this->validateFile($file);
            $path = $this->storage->store($file);
            return Media::create([
                'path' => $path,
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize()
            ]);
        });
    }

    private function validateFile(UploadedFile $file): void 
    {
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
        
        if (!in_array($file->getMimeType(), $allowedTypes)) {
            throw new ValidationException('Invalid file type');
        }

        if ($file->getSize() > 5242880) { // 5MB
            throw new ValidationException('File too large');
        }
    }
}
