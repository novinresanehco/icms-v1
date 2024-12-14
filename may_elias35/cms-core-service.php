<?php

namespace App\Core\CMS;

use App\Core\Security\SecurityManager;
use App\Core\Services\{
    ValidationService,
    CacheManager,
    AuditLogger,
    MonitoringService
};
use App\Core\Repositories\ContentRepository;
use Illuminate\Support\Facades\DB;

class CMSService implements CMSServiceInterface
{
    private SecurityManager $security;
    private ValidationService $validator;
    private ContentRepository $content;
    private CacheManager $cache;
    private AuditLogger $logger;
    private MonitoringService $monitor;

    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        ContentRepository $content,
        CacheManager $cache,
        AuditLogger $logger,
        MonitoringService $monitor
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->content = $content;
        $this->cache = $cache;
        $this->logger = $logger;
        $this->monitor = $monitor;
    }

    public function createContent(array $data): ContentResult
    {
        return $this->security->executeCriticalOperation(
            new CreateContentOperation($data, function() use ($data) {
                $validated = $this->validator->validate($data, [
                    'title' => 'required|max:255',
                    'content' => 'required',
                    'status' => 'required|in:draft,published',
                    'author_id' => 'required|exists:users,id',
                ]);

                DB::beginTransaction();
                
                try {
                    $content = $this->content->create($validated);
                    
                    $this->processMedia($content, $data['media'] ?? []);
                    $this->assignCategories($content, $data['categories'] ?? []);
                    $this->processTags($content, $data['tags'] ?? []);
                    
                    $this->cache->invalidateGroup('content');
                    
                    DB::commit();
                    
                    $this->logger->logContentCreation($content);
                    
                    return new ContentResult($content);
                } catch (\Exception $e) {
                    DB::rollBack();
                    throw $e;
                }
            })
        );
    }

    public function updateContent(int $id, array $data): ContentResult
    {
        return $this->security->executeCriticalOperation(
            new UpdateContentOperation($id, $data, function() use ($id, $data) {
                $content = $this->content->findOrFail($id);
                
                $validated = $this->validator->validate($data, [
                    'title' => 'sometimes|required|max:255',
                    'content' => 'sometimes|required',
                    'status' => 'sometimes|required|in:draft,published',
                ]);

                DB::beginTransaction();
                
                try {
                    $content = $this->content->update($id, $validated);
                    
                    if (isset($data['media'])) {
                        $this->processMedia($content, $data['media']);
                    }
                    
                    if (isset($data['categories'])) {
                        $this->assignCategories($content, $data['categories']);
                    }
                    
                    if (isset($data['tags'])) {
                        $this->processTags($content, $data['tags']);
                    }
                    
                    $this->cache->invalidateGroup('content');
                    
                    DB::commit();
                    
                    $this->logger->logContentUpdate($content);
                    
                    return new ContentResult($content);
                } catch (\Exception $e) {
                    DB::rollBack();
                    throw $e;
                }
            })
        );
    }

    public function deleteContent(int $id): bool
    {
        return $this->security->executeCriticalOperation(
            new DeleteContentOperation($id, function() use ($id) {
                DB::beginTransaction();
                
                try {
                    $content = $this->content->findOrFail($id);
                    
                    $this->removeMedia($content);
                    $this->removeCategories($content);
                    $this->removeTags($content);
                    
                    $this->content->delete($id);
                    
                    $this->cache->invalidateGroup('content');
                    
                    DB::commit();
                    
                    $this->logger->logContentDeletion($content);
                    
                    return true;
                } catch (\Exception $e) {
                    DB::rollBack();
                    throw $e;
                }
            })
        );
    }

    public function getContent(int $id): ?ContentResult
    {
        return $this->cache->remember("content.$id", function() use ($id) {
            $content = $this->content->find($id);
            return $content ? new ContentResult($content) : null;
        });
    }

    public function listContent(array $filters = []): ContentCollection
    {
        $cacheKey = 'content.list.' . md5(serialize($filters));
        
        return $this->cache->remember($cacheKey, function() use ($filters) {
            $validated = $this->validator->validate($filters, [
                'status' => 'sometimes|in:draft,published',
                'category' => 'sometimes|exists:categories,id',
                'tag' => 'sometimes|exists:tags,id',
                'author' => 'sometimes|exists:users,id',
                'search' => 'sometimes|string|max:255',
                'per_page' => 'sometimes|integer|min:1|max:100',
            ]);

            $content = $this->content->list($validated);
            return new ContentCollection($content);
        });
    }

    private function processMedia(Content $content, array $media): void
    {
        foreach ($media as $item) {
            $validated = $this->validator->validate($item, [
                'type' => 'required|in:image,video,document',
                'url' => 'required|url',
                'title' => 'required|string|max:255',
            ]);

            $this->content->attachMedia($content->id, $validated);
        }
    }

    private function assignCategories(Content $content, array $categories): void
    {
        $validated = array_map(function($id) {
            return $this->validator->validate(['id' => $id], [
                'id' => 'required|exists:categories,id'
            ])['id'];
        }, $categories);

        $this->content->syncCategories($content->id, $validated);
    }

    private function processTags(Content $content, array $tags): void
    {
        $validated = array_map(function($tag) {
            return $this->validator->validate(['name' => $tag], [
                'name' => 'required|string|max:50'
            ])['name'];
        }, $tags);

        $this->content->syncTags($content->id, $validated);
    }

    private function removeMedia(Content $content): void
    {
        $this->content->detachAllMedia($content->id);
    }

    private function removeCategories(Content $content): void
    {
        $this->content->detachAllCategories($content->id);
    }

    private function removeTags(Content $content): void
    {
        $this->content->detachAllTags($content->id);
    }
}
