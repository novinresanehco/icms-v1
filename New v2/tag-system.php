<?php

namespace App\Core\Tagging;

use App\Core\Security\SecurityContext;
use App\Core\Validation\ValidationService;
use App\Core\Security\SecurityManager;
use App\Core\Cache\CacheManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Critical tag management system with integrated security and validation
 */
class TagManager implements TagManagerInterface 
{
    private ValidationService $validator;
    private SecurityManager $security;
    private CacheManager $cache;
    private TagRepository $repository;
    private MetricsCollector $metrics;

    public function __construct(
        ValidationService $validator,
        SecurityManager $security,
        CacheManager $cache,
        TagRepository $repository,
        MetricsCollector $metrics
    ) {
        $this->validator = $validator;
        $this->security = $security;
        $this->cache = $cache;
        $this->repository = $repository;
        $this->metrics = $metrics;
    }

    /**
     * Creates a new tag with security validation and error protection
     */
    public function createTag(array $data, SecurityContext $context): Tag
    {
        $startTime = microtime(true);
        DB::beginTransaction();
        
        try {
            // Validate input data
            $validated = $this->validator->validateCriticalData($data, [
                'name' => ['type' => 'string', 'required' => true],
                'type' => ['type' => 'string', 'required' => true],
                'metadata' => ['type' => 'array', 'required' => false]
            ]);

            // Security verification
            $this->security->validateOperation('tag.create', $context);
            
            // Create tag with audit trail
            $tag = $this->repository->create([
                'name' => $validated['name'],
                'type' => $validated['type'],
                'metadata' => $validated['metadata'] ?? [],
                'user_id' => $context->getUserId(),
                'created_at' => now(),
                'audit_trail' => [
                    'created_by' => $context->getUserId(),
                    'ip_address' => $context->getIpAddress(),
                    'timestamp' => now()
                ]
            ]);

            // Invalidate cache
            $this->cache->tags(['tags'])->flush();
            
            DB::commit();

            // Record metrics
            $this->metrics->record('tag.create', [
                'duration' => microtime(true) - $startTime,
                'success' => true
            ]);

            return $tag;
            
        } catch (\Exception $e) {
            DB::rollBack();

            // Log failure metrics
            $this->metrics->record('tag.create', [
                'duration' => microtime(true) - $startTime,
                'success' => false,
                'error' => $e->getMessage()
            ]);

            throw new TagException('Failed to create tag: ' . $e->getMessage());
        }
    }

    /**
     * Attaches tags to content with security validation
     */
    public function attachTags(int $contentId, array $tagIds, SecurityContext $context): void
    {
        DB::beginTransaction();
        
        try {
            // Validate content exists
            $content = Content::findOrFail($contentId);

            // Security checks
            $this->security->validateAccess($content, $context);
            $this->security->validateOperation('tag.attach', $context);

            // Validate tag IDs exist
            $this->validateTagIds($tagIds);

            // Attach tags with audit
            $content->tags()->sync($tagIds);
            $this->logTagAttachment($content, $tagIds, $context);
            
            // Clear relevant cache
            $this->cache->tags([
                'tags', 
                "content:$contentId:tags"
            ])->flush();
            
            DB::commit();
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw new TagException('Failed to attach tags: ' . $e->getMessage());
        }
    }

    /**
     * Retrieves content tags with caching
     */
    public function getContentTags(int $contentId): Collection
    {
        return $this->cache->tags(['tags', "content:$contentId:tags"])
            ->remember("content:$contentId:tags", 3600, function() use ($contentId) {
                return Content::findOrFail($contentId)
                    ->tags()
                    ->get();
            });
    }

    /**
     * Validates that all tag IDs exist
     */
    private function validateTagIds(array $tagIds): void
    {
        $existingCount = $this->repository->whereIn('id', $tagIds)->count();
        if ($existingCount !== count($tagIds)) {
            throw new TagException('One or more tag IDs do not exist');
        }
    }

    /**
     * Logs tag attachment for audit trail
     */
    private function logTagAttachment(Content $content, array $tagIds, SecurityContext $context): void
    {
        AuditLog::create([
            'event' => 'tag.attach',
            'content_id' => $content->id,
            'tag_ids' => $tagIds,
            'user_id' => $context->getUserId(),
            'ip_address' => $context->getIpAddress(),
            'timestamp' => now()
        ]);
    }
}
