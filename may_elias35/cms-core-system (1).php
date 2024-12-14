<?php

namespace App\Core\CMS;

use App\Core\Security\CoreSecurityManager;
use App\Core\Auth\AuthenticationManager;
use Illuminate\Support\Facades\Cache;

class ContentManager implements ContentManagerInterface
{
    private CoreSecurityManager $security;
    private ContentRepository $repository;
    private ValidationService $validator;
    private MediaManager $mediaManager;
    private CacheManager $cache;
    private AuditLogger $auditLogger;

    public function __construct(
        CoreSecurityManager $security,
        ContentRepository $repository,
        ValidationService $validator,
        MediaManager $mediaManager,
        CacheManager $cache,
        AuditLogger $auditLogger
    ) {
        $this->security = $security;
        $this->repository = $repository;
        $this->validator = $validator;
        $this->mediaManager = $mediaManager;
        $this->cache = $cache;
        $this->auditLogger = $auditLogger;
    }

    public function createContent(array $data, User $user): Content
    {
        return $this->security->validateSecureOperation(
            fn() => $this->executeContentCreation($data, $user),
            new SecurityContext('content:create', $data)
        );
    }

    private function executeContentCreation(array $data, User $user): Content
    {
        // Validate content data
        $validatedData = $this->validator->validateContent($data);

        // Process any media attachments
        if (isset($validatedData['media'])) {
            $validatedData['media'] = $this->mediaManager->processMedia(
                $validatedData['media']
            );
        }

        // Create content with version tracking
        $content = DB::transaction(function() use ($validatedData, $user) {
            $content = $this->repository->create([
                ...$validatedData,
                'user_id' => $user->id,
                'status' => ContentStatus::DRAFT
            ]);

            // Create initial version
            $this->repository->createVersion($content, $user);

            return $content;
        });

        // Clear relevant caches
        $this->cache->invalidateContentCaches($content);

        // Log content creation
        $this->auditLogger->logInfo('Content created', [
            'content_id' => $content->id,
            'user_id' => $user->id,
            'type' => $content->type
        ]);

        return $content;
    }

    public function updateContent(int $id, array $data, User $user): Content
    {
        return $this->security->validateSecureOperation(
            fn() => $this->executeContentUpdate($id, $data, $user),
            new SecurityContext('content:update', ['id' => $id, ...$data])
        );
    }

    private function executeContentUpdate(int $id, array $data, User $user): Content
    {
        // Validate update data
        $validatedData = $this->validator->validateContent($data);

        // Process media updates
        if (isset($validatedData['media'])) {
            $validatedData['media'] = $this->mediaManager->processMedia(
                $validatedData['media']
            );
        }

        // Update with version tracking
        $content = DB::transaction(function() use ($id, $validatedData, $user) {
            $content = $this->repository->findOrFail($id);
            
            // Create new version before update
            $this->repository->createVersion($content, $user);
            
            // Perform update
            $content->update($validatedData);
            
            return $content;
        });

        // Invalidate caches
        $this->cache->invalidateContentCaches($content);

        // Log update
        $this->auditLogger->logInfo('Content updated', [
            'content_id' => $content->id,
            'user_id' => $user->id,
            'type' => $content->type
        ]);

        return $content;
    }

    public function publishContent(int $id, User $user): Content
    {
        return $this->security->validateSecureOperation(
            fn() => $this->executeContentPublication($id, $user),
            new SecurityContext('content:publish', ['id' => $id])
        );
    }

    private function executeContentPublication(int $id, User $user): Content
    {
        return DB::transaction(function() use ($id, $user) {
            $content = $this->repository->findOrFail($id);
            
            // Validate content is ready for publication
            $this->validator->validatePublication($content);
            
            // Create published version
            $this->repository->createVersion($content, $user);
            
            // Update status
            $content->update(['status' => ContentStatus::PUBLISHED]);
            
            // Clear caches
            $this->cache->invalidateContentCaches($content);
            
            // Log publication
            $this->auditLogger->logInfo('Content published', [
                'content_id' => $content->id,
                'user_id' => $user->id
            ]);
            
            return $content;
        });
    }

    public function getContent(int $id): Content
    {
        return $this->cache->remember(
            "content:{$id}",
            config('cache.ttl'),
            fn() => $this->repository->findOrFail($id)
        );
    }

    public function searchContent(array $criteria): Collection
    {
        // Validate search criteria
        $validatedCriteria = $this->validator->validateSearchCriteria($criteria);

        return $this->repository->search($validatedCriteria);
    }

    public function deleteContent(int $id, User $user): bool
    {
        return $this->security->validateSecureOperation(
            fn() => $this->executeContentDeletion($id, $user),
            new SecurityContext('content:delete', ['id' => $id])
        );
    }

    private function executeContentDeletion(int $id, User $user): bool
    {
        return DB::transaction(function() use ($id, $user) {
            $content = $this->repository->findOrFail($id);
            
            // Create deletion version for audit
            $this->repository->createVersion($content, $user);
            
            // Remove content
            $content->delete();
            
            // Clear caches
            $this->cache->invalidateContentCaches($content);
            
            // Log deletion
            $this->auditLogger->logInfo('Content deleted', [
                'content_id' => $id,
                'user_id' => $user->id
            ]);
            
            return true;
        });
    }
}
