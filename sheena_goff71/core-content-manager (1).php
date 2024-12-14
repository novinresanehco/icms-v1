<?php

namespace App\Core\Content;

use Illuminate\Support\Facades\{DB, Cache, Log};
use App\Core\Security\SecurityManager;
use App\Core\Interfaces\{ContentManagerInterface, CacheInterface};
use App\Core\Exceptions\{ContentException, ValidationException};

class ContentManager implements ContentManagerInterface
{
    private SecurityManager $security;
    private ContentRepository $repository;
    private CacheInterface $cache;
    private ValidationService $validator;
    private array $config;

    public function __construct(
        SecurityManager $security,
        ContentRepository $repository,
        CacheInterface $cache,
        ValidationService $validator,
        array $config
    ) {
        $this->security = $security;
        $this->repository = $repository;
        $this->cache = $cache;
        $this->validator = $validator;
        $this->config = $config;
    }

    public function createContent(array $data): ContentEntity
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeContentCreation($data),
            ['action' => 'create_content', 'data' => $data]
        );
    }

    public function updateContent(int $id, array $data): ContentEntity
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeContentUpdate($id, $data),
            ['action' => 'update_content', 'id' => $id, 'data' => $data]
        );
    }

    public function publishContent(int $id): bool
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeContentPublication($id),
            ['action' => 'publish_content', 'id' => $id]
        );
    }

    protected function executeContentCreation(array $data): ContentEntity
    {
        $this->validateContentData($data);
        
        DB::beginTransaction();
        try {
            $content = $this->repository->create($data);
            
            $this->processContentMedia($content, $data['media'] ?? []);
            $this->updateContentCache($content);
            
            if ($data['publish'] ?? false) {
                $this->executeContentPublication($content->id);
            }
            
            DB::commit();
            return $content;
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw new ContentException('Content creation failed: ' . $e->getMessage(), 0, $e);
        }
    }

    protected function executeContentUpdate(int $id, array $data): ContentEntity
    {
        $this->validateContentData($data, $id);
        
        DB::beginTransaction();
        try {
            $content = $this->repository->find($id);
            
            if (!$content) {
                throw new ContentException('Content not found');
            }
            
            $this->createContentVersion($content);
            $content = $this->repository->update($id, $data);
            
            $this->processContentMedia($content, $data['media'] ?? []);
            $this->updateContentCache($content);
            
            if ($data['publish'] ?? false) {
                $this->executeContentPublication($content->id);
            }
            
            DB::commit();
            return $content;
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw new ContentException('Content update failed: ' . $e->getMessage(), 0, $e);
        }
    }

    protected function executeContentPublication(int $id): bool
    {
        try {
            $content = $this->repository->find($id);
            
            if (!$content) {
                throw new ContentException('Content not found');
            }
            
            $this->validateContentForPublication($content);
            
            $content->published_at = now();
            $content->status = 'published';
            
            $this->repository->save($content);
            $this->updateContentCache($content);
            
            $this->notifyContentPublished($content);
            
            return true;
            
        } catch (\Exception $e) {
            throw new ContentException('Content publication failed: ' . $e->getMessage(), 0, $e);
        }
    }

    protected function validateContentData(array $data, ?int $id = null): void
    {
        $rules = array_merge(
            $this->config['validation_rules'],
            $id ? ['id' => 'required|exists:contents'] : []
        );

        if (!$this->validator->validate($data, $rules)) {
            throw new ValidationException('Content validation failed');
        }
    }

    protected function validateContentForPublication(ContentEntity $content): void
    {
        if (!$this->validator->validateForPublication($content)) {
            throw new ValidationException('Content not ready for publication');
        }
    }

    protected function processContentMedia(ContentEntity $content, array $media): void
    {
        foreach ($media as $item) {
            $this->validateMediaItem($item);
            $this->repository->attachMedia($content->id, $item['id']);
        }
    }

    protected function createContentVersion(ContentEntity $content): void
    {
        $this->repository->createVersion([
            'content_id' => $content->id,
            'data' => json_encode($content->toArray()),
            'created_by' => auth()->id(),
            'created_at' => now()
        ]);
    }

    protected function updateContentCache(ContentEntity $content): void
    {
        $cacheKey = "content:{$content->id}";
        
        $this->cache->tags(['content'])
            ->put($cacheKey, $content, $this->config['cache_ttl']);
            
        if ($content->status === 'published') {
            $this->cache->tags(['content', 'published'])
                ->put("published:{$content->id}", $content, $this->config['cache_ttl']);
        }
    }

    protected function validateMediaItem(array $media): void
    {
        if (!$this->validator->validateMedia($media)) {
            throw new ValidationException('Invalid media item');
        }
    }

    protected function notifyContentPublished(ContentEntity $content): void
    {
        // Implementation for notifications
    }
}
