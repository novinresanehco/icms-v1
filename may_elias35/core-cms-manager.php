<?php

namespace App\Core\CMS;

use Illuminate\Support\Facades\{DB, Cache};
use App\Core\Security\SecurityManager;
use App\Core\Exceptions\{CMSException, ValidationException};

class ContentManager implements ContentManagerInterface
{
    private SecurityManager $security;
    private ValidationService $validator;
    private MediaHandler $mediaHandler;
    private CacheManager $cache;
    private AuditLogger $auditLogger;

    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        MediaHandler $mediaHandler,
        CacheManager $cache,
        AuditLogger $auditLogger
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->mediaHandler = $mediaHandler;
        $this->cache = $cache;
        $this->auditLogger = $auditLogger;
    }

    /**
     * Create content with security validation and audit trail
     */
    public function createContent(array $data, array $context): Content
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->processContentCreation($data),
            $context
        );
    }

    /**
     * Update content with version control and security checks
     */
    public function updateContent(int $id, array $data, array $context): Content
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->processContentUpdate($id, $data),
            $context
        );
    }

    /**
     * Delete content with security verification and cascading cleanup
     */
    public function deleteContent(int $id, array $context): bool
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->processContentDeletion($id),
            $context
        );
    }

    /**
     * Publish content with workflow validation
     */
    public function publishContent(int $id, array $context): bool
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->processContentPublication($id),
            $context
        );
    }

    /**
     * Retrieve content with caching and security filters
     */
    public function getContent(int $id, array $context): ?Content
    {
        $cacheKey = "content:{$id}";
        
        return $this->cache->remember($cacheKey, 3600, function() use ($id, $context) {
            if (!$this->security->validateAccess('content.view', $context)) {
                throw new SecurityException('Unauthorized content access');
            }

            return $this->findContent($id);
        });
    }

    protected function processContentCreation(array $data): Content
    {
        // Validate content data
        $validatedData = $this->validator->validate($data, [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'status' => 'required|in:draft,published',
            'category_id' => 'required|exists:categories,id'
        ]);

        // Process media attachments if present
        if (isset($data['media'])) {
            $validatedData['media'] = $this->mediaHandler->processMediaFiles($data['media']);
        }

        // Create content with database transaction
        DB::beginTransaction();
        try {
            $content = Content::create($validatedData);
            
            // Process additional content elements
            $this->processContentElements($content, $data);
            
            DB::commit();
            $this->cache->tags(['content'])->flush();
            
            return $content;
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw new CMSException('Content creation failed: ' . $e->getMessage());
        }
    }

    protected function processContentUpdate(int $id, array $data): Content
    {
        $content = $this->findContent($id);
        if (!$content) {
            throw new CMSException('Content not found');
        }

        // Create content version before update
        $this->createContentVersion($content);

        // Validate and update content
        $validatedData = $this->validator->validate($data, [
            'title' => 'string|max:255',
            'content' => 'string',
            'status' => 'in:draft,published',
            'category_id' => 'exists:categories,id'
        ]);

        DB::beginTransaction();
        try {
            $content->update($validatedData);
            
            // Update media if needed
            if (isset($data['media'])) {
                $this->mediaHandler->updateContentMedia($content, $data['media']);
            }
            
            DB::commit();
            $this->cache->tags(['content'])->flush();
            
            return $content;
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw new CMSException('Content update failed: ' . $e->getMessage());
        }
    }

    protected function processContentDeletion(int $id): bool
    {
        $content = $this->findContent($id);
        if (!$content) {
            throw new CMSException('Content not found');
        }

        DB::beginTransaction();
        try {
            // Cleanup associated media
            $this->mediaHandler->deleteContentMedia($content);
            
            // Remove content and related data
            $content->delete();
            
            DB::commit();
            $this->cache->tags(['content'])->flush();
            
            return true;
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw new CMSException('Content deletion failed: ' . $e->getMessage());
        }
    }

    protected function processContentPublication(int $id): bool
    {
        $content = $this->findContent($id);
        if (!$content) {
            throw new CMSException('Content not found');
        }

        // Validate content is ready for publication
        if (!$this->validator->validateForPublication($content)) {
            throw new ValidationException('Content not ready for publication');
        }

        DB::beginTransaction();
        try {
            $content->status = 'published';
            $content->published_at = now();
            $content->save();
            
            DB::commit();
            $this->cache->tags(['content'])->flush();
            
            return true;
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw new CMSException('Content publication failed: ' . $e->getMessage());
        }
    }

    protected function findContent(int $id): ?Content
    {
        return Content::with(['category', 'media', 'versions'])
            ->findOrFail($id);
    }

    protected function createContentVersion(Content $content): void
    {
        ContentVersion::create([
            'content_id' => $content->id,
            'title' => $content->title,
            'content' => $content->content,
            'version' => $this->getNextVersionNumber($content),
            'created_by' => auth()->id()
        ]);
    }

    protected function getNextVersionNumber(Content $content): int
    {
        return $content->versions()->max('version') + 1;
    }

    protected function processContentElements(Content $content, array $data): void
    {
        // Process tags
        if (isset($data['tags'])) {
            $content->syncTags($data['tags']);
        }

        // Process meta data
        if (isset($data['meta'])) {
            $content->updateMeta($data['meta']);
        }
    }
}
