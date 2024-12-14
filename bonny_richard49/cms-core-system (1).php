<?php

namespace App\Core\CMS;

use App\Core\Auth\AuthenticationManager;
use App\Core\Security\SecurityManager;
use Illuminate\Support\Facades\{DB, Cache};
use App\Core\Exceptions\{ContentException, SecurityException};

class CMSManager
{
    private AuthenticationManager $auth;
    private SecurityManager $security;
    private ContentRepository $content;
    private MediaManager $media;
    private CategoryManager $categories;

    public function __construct(
        AuthenticationManager $auth,
        SecurityManager $security,
        ContentRepository $content,
        MediaManager $media,
        CategoryManager $categories
    ) {
        $this->auth = $auth;
        $this->security = $security;
        $this->content = $content;
        $this->media = $media;
        $this->categories = $categories;
    }

    /**
     * Create content with full security validation and media handling
     */
    public function createContent(array $data, array $media = []): ContentResult
    {
        return $this->security->executeCriticalOperation(function() use ($data, $media) {
            DB::beginTransaction();
            try {
                // Create content
                $content = $this->content->create($this->validateContentData($data));
                
                // Handle media attachments
                if (!empty($media)) {
                    $this->media->attachToContent($content->id, $media);
                }
                
                // Handle categories
                if (!empty($data['categories'])) {
                    $this->categories->assignToContent($content->id, $data['categories']);
                }
                
                // Create initial version
                $this->content->createVersion($content->id);
                
                DB::commit();
                Cache::tags(['content'])->flush();
                
                return new ContentResult($content);
                
            } catch (\Exception $e) {
                DB::rollBack();
                throw new ContentException('Content creation failed: ' . $e->getMessage(), 0, $e);
            }
        });
    }

    /**
     * Update content with version control and security
     */
    public function updateContent(int $id, array $data): ContentResult
    {
        return $this->security->executeCriticalOperation(function() use ($id, $data) {
            DB::beginTransaction();
            try {
                // Verify access
                $this->verifyContentAccess($id, 'edit');
                
                // Create new version before update
                $this->content->createVersion($id);
                
                // Update content
                $content = $this->content->update($id, $this->validateContentData($data));
                
                // Update relationships
                $this->updateContentRelations($content, $data);
                
                DB::commit();
                Cache::tags(['content'])->flush();
                
                return new ContentResult($content);
                
            } catch (\Exception $e) {
                DB::rollBack();
                throw new ContentException('Content update failed: ' . $e->getMessage(), 0, $e);
            }
        });
    }

    /**
     * Manage content workflow with security validation
     */
    public function changeContentStatus(int $id, string $status): ContentResult
    {
        return $this->security->executeCriticalOperation(function() use ($id, $status) {
            // Verify workflow permissions
            $this->verifyWorkflowPermission($id, $status);
            
            // Update status
            $content = $this->content->updateStatus($id, $status);
            
            // Handle workflow specific actions
            $this->processWorkflowHooks($content, $status);
            
            return new ContentResult($content);
        });
    }

    /**
     * Media management with security and optimization
     */
    public function handleMedia(UploadedFile $file, array $options = []): MediaResult
    {
        return $this->security->executeCriticalOperation(function() use ($file, $options) {
            // Validate and store media
            $media = $this->media->store($file, $options);
            
            // Process and optimize
            $this->media->process($media->id);
            
            return new MediaResult($media);
        });
    }

    /**
     * Category management with security
     */
    public function manageCategories(array $data): CategoryResult
    {
        return $this->security->executeCriticalOperation(function() use ($data) {
            return new CategoryResult(
                $this->categories->manage($data)
            );
        });
    }

    /**
     * Version management and restoration
     */
    public function restoreVersion(int $contentId, int $versionId): ContentResult
    {
        return $this->security->executeCriticalOperation(function() use ($contentId, $versionId) {
            DB::beginTransaction();
            try {
                // Verify version access
                $this->verifyVersionAccess($contentId, $versionId);
                
                // Create new version before restore
                $this->content->createVersion($contentId);
                
                // Restore version
                $content = $this->content->restoreVersion($contentId, $versionId);
                
                DB::commit();
                Cache::tags(['content'])->flush();
                
                return new ContentResult($content);
                
            } catch (\Exception $e) {
                DB::rollBack();
                throw new ContentException('Version restoration failed: ' . $e->getMessage(), 0, $e);
            }
        });
    }

    private function validateContentData(array $data): array
    {
        $rules = [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'status' => 'required|in:draft,published,archived',
            'categories' => 'array',
            'categories.*' => 'exists:categories,id',
            'meta' => 'array'
        ];

        $validator = validator($data, $rules);
        
        if ($validator->fails()) {
            throw new ContentException('Invalid content data: ' . json_encode($validator->errors()));
        }

        return $data;
    }

    private function verifyContentAccess(int $contentId, string $action): void
    {
        if (!$this->security->hasPermission("content.$action")) {
            throw new SecurityException("Access denied for action: $action");
        }
    }

    private function verifyWorkflowPermission(int $contentId, string $status): void
    {
        if (!$this->security->hasPermission("workflow.$status")) {
            throw new SecurityException("Access denied for workflow status: $status");
        }
    }

    private function verifyVersionAccess(int $contentId, int $versionId): void
    {
        if (!$this->security->hasPermission('content.version.restore')) {
            throw new SecurityException('Access denied for version restoration');
        }
    }

    private function updateContentRelations(Content $content, array $data): void
    {
        if (isset($data['categories'])) {
            $this->categories->assignToContent($content->id, $data['categories']);
        }
        
        if (isset($data['media'])) {
            $this->media->updateContentMedia($content->id, $data['media']);
        }
    }

    private function processWorkflowHooks(Content $content, string $status): void
    {
        switch ($status) {
            case 'published':
                $this->handlePublishHooks($content);
                break;
            case 'archived':
                $this->handleArchiveHooks($content);
                break;
        }
    }

    private function handlePublishHooks(Content $content): void
    {
        // Send notifications
        // Update search index
        // Clear relevant caches
    }

    private function handleArchiveHooks(Content $content): void
    {
        // Remove from search index
        // Archive related data
        // Update caches
    }
}
