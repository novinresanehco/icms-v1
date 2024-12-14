<?php

namespace App\Core\CMS;

use App\Core\Security\SecurityManager;
use App\Core\Validation\ValidationService;
use App\Core\Cache\CacheManager;
use Illuminate\Support\Facades\DB;

class ContentManager implements ContentManagerInterface 
{
    private SecurityManager $security;
    private ValidationService $validator;
    private CacheManager $cache;
    private ContentRepository $repository;
    private MetricsCollector $metrics;

    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        CacheManager $cache,
        ContentRepository $repository,
        MetricsCollector $metrics
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->cache = $cache;
        $this->repository = $repository;
        $this->metrics = $metrics;
    }

    public function create(array $data, OperationContext $context): Content 
    {
        return $this->security->executeSecureOperation(
            fn() => $this->executeCreate($data),
            $context
        );
    }

    public function update(int $id, array $data, OperationContext $context): Content 
    {
        return $this->security->executeSecureOperation(
            fn() => $this->executeUpdate($id, $data),
            $context
        );
    }

    public function delete(int $id, OperationContext $context): bool 
    {
        return $this->security->executeSecureOperation(
            fn() => $this->executeDelete($id),
            $context
        );
    }

    public function publish(int $id, OperationContext $context): bool 
    {
        return $this->security->executeSecureOperation(
            fn() => $this->executePublish($id),
            $context
        );
    }

    private function executeCreate(array $data): Content 
    {
        $this->validator->validate($data, $this->getContentRules());
        
        DB::beginTransaction();
        try {
            $content = $this->repository->create($data);
            $this->processMedia($content, $data['media'] ?? []);
            $this->updateCache($content);
            
            DB::commit();
            return $content;
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function executeUpdate(int $id, array $data): Content 
    {
        $this->validator->validate($data, $this->getContentRules());
        
        DB::beginTransaction();
        try {
            $content = $this->repository->update($id, $data);
            $this->processMedia($content, $data['media'] ?? []);
            $this->updateCache($content);
            
            DB::commit();
            return $content;
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function executeDelete(int $id): bool 
    {
        DB::beginTransaction();
        try {
            $deleted = $this->repository->delete($id);
            $this->clearCache($id);
            
            DB::commit();
            return $deleted;
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function executePublish(int $id): bool 
    {
        DB::beginTransaction();
        try {
            $published = $this->repository->publish($id);
            $this->updateCache($this->repository->find($id));
            
            DB::commit();
            return $published;
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function processMedia(Content $content, array $media): void 
    {
        foreach ($media as $item) {
            $this->validator->validate($item, $this->getMediaRules());
            $this->repository->attachMedia($content->id, $item);
        }
    }

    private function updateCache(Content $content): void 
    {
        $this->cache->set(
            $this->getCacheKey($content->id),
            $content,
            $this->getCacheTtl()
        );
    }

    private function clearCache(int $id): void 
    {
        $this->cache->delete($this->getCacheKey($id));
    }

    private function getCacheKey(int $id): string 
    {
        return "content:{$id}";
    }

    private function getCacheTtl(): int 
    {
        return config('cms.cache.ttl', 3600);
    }

    private function getContentRules(): array 
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string'],
            'status' => ['required', 'in:draft,published'],
            'author_id' => ['required', 'exists:users,id'],
            'category_id' => ['required', 'exists:categories,id'],
            'tags' => ['array'],
            'media' => ['array']
        ];
    }

    private function getMediaRules(): array 
    {
        return [
            'type' => ['required', 'in:image,video,document'],
            'url' => ['required', 'url'],
            'title' => ['required', 'string', 'max:255'],
            'size' => ['required', 'integer', 'max:' . config('cms.media.max_size')]
        ];
    }
}
