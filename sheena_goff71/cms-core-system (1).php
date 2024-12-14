<?php

namespace App\Core\CMS;

use App\Core\Security\SecurityManager;
use App\Core\Auth\AuthenticationManager;
use App\Core\CMS\Events\ContentEvent;
use App\Core\CMS\Exceptions\{ContentException, ValidationException};
use Illuminate\Support\Facades\{DB, Cache, Log};

class ContentManager
{
    protected SecurityManager $security;
    protected AuthenticationManager $auth;
    protected ValidationService $validator;
    protected CacheManager $cache;
    protected AuditLogger $auditLogger;

    // Critical operation constants
    private const CACHE_TTL = 3600;
    private const MAX_REVISION_COUNT = 50;
    private const BATCH_SIZE = 100;

    public function __construct(
        SecurityManager $security,
        AuthenticationManager $auth,
        ValidationService $validator,
        CacheManager $cache,
        AuditLogger $auditLogger
    ) {
        $this->security = $security;
        $this->auth = $auth;
        $this->validator = $validator;
        $this->cache = $cache;
        $this->auditLogger = $auditLogger;
    }

    /**
     * Create new content with security validation and versioning
     * 
     * @throws ContentException
     * @throws ValidationException
     */
    public function createContent(array $data, User $user): Content
    {
        return $this->security->executeCriticalOperation(function() use ($data, $user) {
            // Validate content data
            $validatedData = $this->validator->validateContent($data);
            
            // Create content with versioning
            $content = DB::transaction(function() use ($validatedData, $user) {
                // Create main content
                $content = new Content($validatedData);
                $content->created_by = $user->id;
                $content->save();
                
                // Create initial version
                $this->createContentVersion($content, $validatedData, $user);
                
                // Handle media attachments
                if (!empty($validatedData['media'])) {
                    $this->processMediaAttachments($content, $validatedData['media']);
                }
                
                return $content;
            });
            
            // Clear relevant caches
            $this->cache->tags(['content'])->flush();
            
            // Log content creation
            $this->auditLogger->logContentCreation($content, $user);
            
            return $content;
        }, ['context' => 'content_creation', 'user_id' => $user->id]);
    }

    /**
     * Update existing content with version control
     */
    public function updateContent(int $id, array $data, User $user): Content
    {
        return $this->security->executeCriticalOperation(function() use ($id, $data, $user) {
            $content = Content::findOrFail($id);
            
            // Verify update permissions
            if (!$this->auth->canUpdate($user, $content)) {
                throw new ContentException('Unauthorized content update attempt');
            }
            
            // Validate update data
            $validatedData = $this->validator->validateContentUpdate($data);
            
            // Perform update with versioning
            DB::transaction(function() use ($content, $validatedData, $user) {
                // Create new version before update
                $this->createContentVersion($content, $content->toArray(), $user);
                
                // Update content
                $content->update($validatedData);
                
                // Handle media updates
                if (isset($validatedData['media'])) {
                    $this->updateMediaAttachments($content, $validatedData['media']);
                }
                
                // Manage version history
                $this->manageVersionHistory($content);
            });
            
            // Clear content caches
            $this->clearContentCache($content);
            
            // Log content update
            $this->auditLogger->logContentUpdate($content, $user);
            
            return $content->fresh();
        }, ['context' => 'content_update', 'user_id' => $user->id, 'content_id' => $id]);
    }

    /**
     * Retrieve content with caching and security checks
     */
    public function getContent(int $id, ?User $user = null): Content
    {
        return $this->cache->remember("content.$id", self::CACHE_TTL, function() use ($id, $user) {
            $content = Content::with(['versions', 'media'])->findOrFail($id);
            
            // Verify read permissions
            if (!$this->auth->canView($user, $content)) {
                throw new ContentException('Unauthorized content access attempt');
            }
            
            return $content;
        });
    }

    /**
     * Delete content with security verification
     */
    public function deleteContent(int $id, User $user): bool
    {
        return $this->security->executeCriticalOperation(function() use ($id, $user) {
            $content = Content::findOrFail($id);
            
            // Verify deletion permissions
            if (!$this->auth->canDelete($user, $content)) {
                throw new ContentException('Unauthorized content deletion attempt');
            }
            
            // Perform soft delete
            DB::transaction(function() use ($content) {
                // Archive content
                $content->archived_at = now();
                $content->save();
                
                // Archive associated media
                $content->media()->update(['archived_at' => now()]);
            });
            
            // Clear caches
            $this->clearContentCache($content);
            
            // Log deletion
            $this->auditLogger->logContentDeletion($content, $user);
            
            return true;
        }, ['context' => 'content_deletion', 'user_id' => $user->id, 'content_id' => $id]);
    }

    /**
     * Create version record for content changes
     */
    protected function createContentVersion(Content $content, array $data, User $user): ContentVersion
    {
        $version = new ContentVersion([
            'content_id' => $content->id,
            'data' => json_encode($data),
            'created_by' => $user->id,
            'version_number' => $content->versions()->count() + 1
        ]);
        
        $version->save();
        return $version;
    }

    /**
     * Manage version history to prevent excessive storage
     */
    protected function manageVersionHistory(Content $content): void
    {
        $versions = $content->versions()
            ->orderBy('created_at', 'desc')
            ->get();
            
        if ($versions->count() > self::MAX_REVISION_COUNT) {
            $versionsToDelete = $versions->slice(self::MAX_REVISION_COUNT);
            ContentVersion::whereIn('id', $versionsToDelete->pluck('id'))->delete();
        }
    }

    /**
     * Handle media attachment processing
     */
    protected function processMediaAttachments(Content $content, array $media): void
    {
        foreach ($media as $mediaItem) {
            if ($this->validator->validateMedia($mediaItem)) {
                $content->media()->attach($mediaItem['id'], [
                    'order' => $mediaItem['order'] ?? 0,
                    'caption' => $mediaItem['caption'] ?? null
                ]);
            }
        }
    }

    /**
     * Update media attachments for content
     */
    protected function updateMediaAttachments(Content $content, array $media): void
    {
        // Detach existing media
        $content->media()->detach();
        
        // Attach updated media
        $this->processMediaAttachments($content, $media);
    }

    /**
     * Clear content-related caches
     */
    protected function clearContentCache(Content $content): void
    {
        $this->cache->tags(['content'])->forget("content.{$content->id}");
        $this->cache->tags(['content'])->forget("content.list");
    }
}
