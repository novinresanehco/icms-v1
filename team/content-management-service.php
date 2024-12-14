<?php

namespace App\Core\Content;

use App\Core\Security\SecurityManager;
use App\Core\Cache\CacheManager;
use App\Core\Events\ContentEvent;
use App\Core\Exceptions\ContentException;
use App\Core\Repositories\ContentRepository;
use Illuminate\Support\Facades\DB;

class ContentManagementService implements ContentManagementInterface
{
    private SecurityManager $security;
    private ContentRepository $repository;
    private CacheManager $cache;

    public function __construct(
        SecurityManager $security,
        ContentRepository $repository,
        CacheManager $cache
    ) {
        $this->security = $security;
        $this->repository = $repository;
        $this->cache = $cache;
    }

    public function create(array $data, array $context): Content
    {
        return $this->security->executeCriticalOperation(
            function() use ($data, $context) {
                DB::beginTransaction();
                try {
                    $content = $this->repository->create([
                        'title' => $data['title'],
                        'content' => $data['content'],
                        'status' => ContentStatus::DRAFT,
                        'user_id' => $context['user_id'],
                        'metadata' => $this->prepareMetadata($data),
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);

                    event(new ContentEvent('created', $content));
                    
                    $this->cache->tags(['content'])->put(
                        $this->getCacheKey($content->id),
                        $content,
                        config('cache.ttl')
                    );

                    DB::commit();
                    return $content;
                } catch (\Exception $e) {
                    DB::rollBack();
                    throw new ContentException('Content creation failed: ' . $e->getMessage());
                }
            },
            $context
        );
    }

    public function update(int $id, array $data, array $context): Content
    {
        return $this->security->executeCriticalOperation(
            function() use ($id, $data, $context) {
                DB::beginTransaction();
                try {
                    $content = $this->repository->findOrFail($id);
                    
                    $this->validateOwnership($content, $context);

                    $content = $this->repository->update($content->id, [
                        'title' => $data['title'] ?? $content->title,
                        'content' => $data['content'] ?? $content->content,
                        'metadata' => $this->prepareMetadata($data),
                        'updated_at' => now()
                    ]);

                    event(new ContentEvent('updated', $content));
                    
                    $this->cache->tags(['content'])->put(
                        $this->getCacheKey($content->id),
                        $content,
                        config('cache.ttl')
                    );

                    DB::commit();
                    return $content;
                } catch (\Exception $e) {
                    DB::rollBack();
                    throw new ContentException('Content update failed: ' . $e->getMessage());
                }
            },
            $context
        );
    }

    public function publish(int $id, array $context): Content
    {
        return $this->security->executeCriticalOperation(
            function() use ($id, $context) {
                DB::beginTransaction();
                try {
                    $content = $this->repository->findOrFail($id);
                    
                    $this->validateOwnership($content, $context);

                    $content = $this->repository->update($content->id, [
                        'status' => ContentStatus::PUBLISHED,
                        'published_at' => now()
                    ]);

                    event(new ContentEvent('published', $content));
                    
                    $this->cache->tags(['content'])->put(
                        $this->getCacheKey($content->id),
                        $content,
                        config('cache.ttl')
                    );

                    DB::commit();
                    return $content;
                } catch (\Exception $e) {
                    DB::rollBack();
                    throw new ContentException('Content publication failed: ' . $e->getMessage());
                }
            },
            $context
        );
    }

    public function delete(int $id, array $context): bool
    {
        return $this->security->executeCriticalOperation(
            function() use ($id, $context) {
                DB::beginTransaction();
                try {
                    $content = $this->repository->findOrFail($id);
                    
                    $this->validateOwnership($content, $context);

                    $this->repository->delete($content->id);
                    
                    event(new ContentEvent('deleted', $content));
                    
                    $this->cache->tags(['content'])->forget(
                        $this->getCacheKey($content->id)
                    );

                    DB::commit();
                    return true;
                } catch (\Exception $e) {
                    DB::rollBack();
                    throw new ContentException('Content deletion failed: ' . $e->getMessage());
                }
            },
            $context
        );
    }

    protected function validateOwnership(Content $content, array $context): void
    {
        if ($content->user_id !== $context['user_id'] && !$context['is_admin']) {
            throw new ContentException('Unauthorized content access');
        }
    }

    protected function prepareMetadata(array $data): array
    {
        return array_filter([
            'seo_title' => $data['seo_title'] ?? null,
            'seo_description' => $data['seo_description'] ?? null,
            'tags' => $data['tags'] ?? [],
            'categories' => $data['categories'] ?? [],
            'template' => $data['template'] ?? 'default'
        ]);
    }

    protected function getCacheKey(int $id): string
    {
        return "content.{$id}";
    }
}
