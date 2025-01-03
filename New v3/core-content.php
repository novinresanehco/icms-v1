<?php

namespace App\Core\Content;

class ContentManager implements ContentManagerInterface 
{
    private SecurityManager $security;
    private Repository $repository;
    private CacheService $cache;
    private AuditLogger $audit;

    public function store(array $data): Content 
    {
        DB::beginTransaction();
        try {
            $secured = $this->security->validateAndSecure($data);
            $content = $this->repository->create($secured);
            
            $this->audit->logContent('create', $content->id);
            $this->cache->forget(['content', $content->id]);
            
            DB::commit();
            return $content;
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw new ContentException('Store failed', 0, $e);
        }
    }

    public function update(int $id, array $data): Content 
    {
        DB::beginTransaction();
        try {
            $secured = $this->security->validateAndSecure($data);
            $content = $this->repository->update($id, $secured);
            
            $this->audit->logContent('update', $id);
            $this->cache->forget(['content', $id]);
            
            DB::commit();
            return $content;
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw new ContentException('Update failed', 0, $e);
        }
    }

    public function retrieve(int $id): Content 
    {
        return $this->cache->remember(
            ['content', $id],
            fn() => $this->repository->find($id)
        );
    }
}

class MediaManager implements MediaManagerInterface 
{
    private StorageService $storage;
    private SecurityManager $security;
    private AuditLogger $audit;

    public function store(UploadedFile $file): Media 
    {
        $this->validateFile($file);
        $path = $this->storage->store($file);
        
        return DB::transaction(function() use ($file, $path) {
            $media = $this->createMedia($file, $path);
            $this->audit->logMedia('upload', $media->id);
            return $media;
        });
    }

    private function validateFile(UploadedFile $file): void 
    {
        $this->security->validateFile($file);
    }
}
