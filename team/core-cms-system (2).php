<?php

namespace App\Core\CMS;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Validation\ValidationService;
use App\Core\Cache\CacheManager;
use Illuminate\Support\Facades\DB;

class ContentManager implements ContentManagerInterface
{
    private SecurityManagerInterface $security;
    private ValidationService $validator;
    private CacheManager $cache;
    private Repository $repository;

    public function __construct(
        SecurityManagerInterface $security,
        ValidationService $validator,
        CacheManager $cache,
        Repository $repository
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->cache = $cache;
        $this->repository = $repository;
    }

    public function create(array $data): Content
    {
        return $this->security->executeCriticalOperation(
            function() use ($data) {
                $validated = $this->validator->validateContent($data);
                
                DB::beginTransaction();
                try {
                    $content = $this->repository->create($validated);
                    $this->processMedia($content, $data['media'] ?? []);
                    $this->updateCache($content);
                    
                    DB::commit();
                    return $content;
                } catch (\Throwable $e) {
                    DB::rollBack();
                    throw $e;
                }
            },
            new SecurityContext('content.create', $data)
        );
    }

    public function update(int $id, array $data): Content
    {
        return $this->security->executeCriticalOperation(
            function() use ($id, $data) {
                $content = $this->repository->findOrFail($id);
                $validated = $this->validator->validateContent($data);
                
                DB::beginTransaction();
                try {
                    $content->update($validated);
                    $this->processMedia($content, $data['media'] ?? []);
                    $this->updateCache($content);
                    $this->createVersion($content);
                    
                    DB::commit();
                    return $content;
                } catch (\Throwable $e) {
                    DB::rollBack();
                    throw $e;
                }
            },
            new SecurityContext('content.update', ['id' => $id, 'data' => $data])
        );
    }

    public function delete(int $id): bool
    {
        return $this->security->executeCriticalOperation(
            function() use ($id) {
                $content = $this->repository->findOrFail($id);
                
                DB::beginTransaction();
                try {
                    $this->cleanupMedia($content);
                    $content->delete();
                    $this->removeFromCache($content);
                    
                    DB::commit();
                    return true;
                } catch (\Throwable $e) {
                    DB::rollBack();
                    throw $e;
                }
            },
            new SecurityContext('content.delete', ['id' => $id])
        );
    }

    public function publish(int $id): bool
    {
        return $this->security->executeCriticalOperation(
            function() use ($id) {
                $content = $this->repository->findOrFail($id);
                
                DB::beginTransaction();
                try {
                    $content->publish();
                    $this->updateCache($content);
                    $this->createVersion($content);
                    
                    DB::commit();
                    return true;
                } catch (\Throwable $e) {
                    DB::rollBack();
                    throw $e;
                }
            },
            new SecurityContext('content.publish', ['id' => $id])
        );
    }

    public function find(int $id): ?Content
    {
        return $this->cache->remember(
            "content.$id",
            fn() => $this->repository->find($id)
        );
    }

    private function processMedia(Content $content, array $media): void
    {
        foreach ($media as $item) {
            $this->validator->validateMedia($item);
            $content->attachMedia($item);
        }
    }

    private function cleanupMedia(Content $content): void
    {
        foreach ($content->media as $media) {
            $media->delete();
        }
    }

    private function updateCache(Content $content): void
    {
        $this->cache->put(
            "content.{$content->id}",
            $content,
            config('cms.cache.ttl')
        );
    }

    private function removeFromCache(Content $content): void
    {
        $this->cache->forget("content.{$content->id}");
    }

    private function createVersion(Content $content): void
    {
        $this->repository->createVersion($content);
    }
}
