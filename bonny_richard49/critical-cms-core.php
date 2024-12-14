<?php

namespace App\Core\CMS;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Repository\{ContentRepository, MediaRepository, CategoryRepository};
use App\Core\Cache\CacheManager;
use App\Core\Events\ContentEvent;
use Illuminate\Support\Facades\DB;

class CriticalCMSManager implements CMSManagerInterface 
{
    private SecurityManagerInterface $security;
    private ContentRepository $content;
    private MediaRepository $media;
    private CategoryRepository $category;
    private CacheManager $cache;
    private AuditLogger $auditLogger;

    public function __construct(
        SecurityManagerInterface $security,
        ContentRepository $content,
        MediaRepository $media,
        CategoryRepository $category,
        CacheManager $cache,
        AuditLogger $auditLogger
    ) {
        $this->security = $security;
        $this->content = $content;
        $this->media = $media;
        $this->category = $category;
        $this->cache = $cache;
        $this->auditLogger = $auditLogger;
    }

    /**
     * Create content with security validation and versioning
     */
    public function createContent(array $data, User $user): ContentResult 
    {
        return $this->security->executeCriticalOperation(
            new ContentOperation('create', $data),
            function() use ($data, $user) {
                // Validate all content data
                $validatedData = $this->validateContentData($data);

                DB::beginTransaction();
                try {
                    // Create content version
                    $content = $this->content->create([
                        ...$validatedData,
                        'created_by' => $user->id,
                        'version' => 1
                    ]);

                    // Process media attachments
                    if (!empty($data['media'])) {
                        $this->processMediaAttachments($content, $data['media']);
                    }

                    // Handle categories
                    if (!empty($data['categories'])) {
                        $this->processCategories($content, $data['categories']);
                    }

                    // Clear relevant caches
                    $this->cache->tags(['content'])->flush();

                    DB::commit();

                    // Log content creation
                    $this->auditLogger->logContentChange(
                        ContentEvent::CREATED,
                        $content,
                        $user
                    );

                    return new ContentResult($content);

                } catch (\Exception $e) {
                    DB::rollBack();
                    throw $e;
                }
            }
        );
    }

    /**
     * Update content with version control
     */
    public function updateContent(int $id, array $data, User $user): ContentResult 
    {
        return $this->security->executeCriticalOperation(
            new ContentOperation('update', $data),
            function() use ($id, $data, $user) {
                $validatedData = $this->validateContentData($data);

                DB::beginTransaction();
                try {
                    // Get current content
                    $content = $this->content->findOrFail($id);

                    // Create new version
                    $newVersion = $content->version + 1;
                    
                    // Store previous version
                    $this->content->createVersion($content);

                    // Update content
                    $content->update([
                        ...$validatedData,
                        'updated_by' => $user->id,
                        'version' => $newVersion
                    ]);

                    // Update media
                    if (isset($data['media'])) {
                        $this->processMediaAttachments($content, $data['media']);
                    }

                    // Update categories
                    if (isset($data['categories'])) {
                        $this->processCategories($content, $data['categories']);
                    }

                    // Clear caches
                    $this->cache->tags(['content'])->flush();

                    DB::commit();

                    $this->auditLogger->logContentChange(
                        ContentEvent::UPDATED,
                        $content,
                        $user
                    );

                    return new ContentResult($content);

                } catch (\Exception $e) {
                    DB::rollBack();
                    throw $e;
                }
            }
        );
    }

    /**
     * Process media attachments securely
     */
    private function processMediaAttachments(Content $content, array $mediaIds): void 
    {
        // Validate media exists and user has access
        $media = $this->media->findAllOrFail($mediaIds);
        
        // Verify media security
        foreach ($media as $item) {
            if (!$this->security->canAccessMedia($item)) {
                throw new SecurityException("Unauthorized media access: {$item->id}");
            }
        }

        // Attach media
        $content->media()->sync($mediaIds);
    }

    /**
     * Process category assignments with validation
     */
    private function processCategories(Content $content, array $categoryIds): void 
    {
        // Validate categories exist
        $categories = $this->category->findAllOrFail($categoryIds);
        
        // Verify category access
        foreach ($categories as $category) {
            if (!$this->security->canAccessCategory($category)) {
                throw new SecurityException("Unauthorized category access: {$category->id}");
            }
        }

        // Assign categories
        $content->categories()->sync($categoryIds);
    }

    /**
     * Validate content data against rules
     */
    private function validateContentData(array $data): array 
    {
        return $this->content->validate($data, [
            'title' => 'required|string|max:200',
            'body' => 'required|string',
            'status' => 'required|in:draft,published,archived',
            'media.*' => 'exists:media,id',
            'categories.*' => 'exists:categories,id'
        ]);
    }
}
