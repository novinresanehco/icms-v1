<?php

namespace App\Core\CMS;

use App\Core\Security\SecurityManager;
use App\Core\Protection\CoreProtectionSystem;
use App\Core\Validation\ValidationService;
use App\Core\Cache\CacheManager;
use Illuminate\Support\Facades\DB;

class ContentManager implements ContentManagerInterface
{
    protected SecurityManager $security;
    protected ValidationService $validator;
    protected CacheManager $cache;
    protected CoreProtectionSystem $protection;
    
    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        CacheManager $cache, 
        CoreProtectionSystem $protection
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->cache = $cache;
        $this->protection = $protection;
    }

    public function createContent(array $data, SecurityContext $context): ContentResult 
    {
        return $this->protection->executeProtectedOperation(
            fn() => $this->processContentCreation($data),
            $context,
            'content.create'
        );
    }

    protected function processContentCreation(array $data): ContentResult
    {
        $validatedData = $this->validator->validateContent($data);
        
        DB::beginTransaction();
        try {
            $content = Content::create($validatedData);
            
            $this->processMediaAttachments($content, $data['media'] ?? []);
            $this->updateContentIndex($content);
            $this->invalidateContentCache($content);
            
            DB::commit();
            return new ContentResult($content);
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw new ContentException("Content creation failed: {$e->getMessage()}", 0, $e);
        }
    }

    protected function updateContentIndex(Content $content): void 
    {
        $this->searchIndex->updateDocument($content);
    }

    protected function invalidateContentCache(Content $content): void
    {
        $this->cache->tags(['content'])->forget("content.{$content->id}");
        $this->cache->tags(['content'])->forget('content.list');
    }
}

class MediaManager implements MediaManagerInterface 
{
    protected SecurityManager $security;
    protected ValidationService $validator;
    protected StorageManager $storage;
    protected CoreProtectionSystem $protection;

    public function processMedia(UploadedFile $file, SecurityContext $context): MediaResult
    {
        return $this->protection->executeProtectedOperation(
            fn() => $this->handleMediaProcessing($file),
            $context,
            'media.process'
        );
    }

    protected function handleMediaProcessing(UploadedFile $file): MediaResult
    {
        $this->validator->validateMediaFile($file);
        
        DB::beginTransaction();
        try {
            $media = $this->storage->storeSecurely($file);
            $this->processOptimization($media);
            $this->generateThumbnails($media);
            
            DB::commit();
            return new MediaResult($media);
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->storage->cleanupFailedUpload($file);
            throw new MediaException("Media processing failed: {$e->getMessage()}", 0, $e);
        }
    }
}

class VersionManager implements VersionManagerInterface
{
    protected SecurityManager $security;
    protected ValidationService $validator;
    protected StorageManager $storage;

    public function createVersion(Content $content, SecurityContext $context): VersionResult
    {
        return $this->protection->executeProtectedOperation(
            fn() => $this->processVersionCreation($content),
            $context,
            'version.create'
        );
    }

    protected function processVersionCreation(Content $content): VersionResult
    {
        DB::beginTransaction();
        try {
            $version = $this->createVersionSnapshot($content);
            $this->storeVersionData($version, $content);
            
            DB::commit();
            return new VersionResult($version);
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw new VersionException("Version creation failed: {$e->getMessage()}", 0, $e);
        }
    }
}

class WorkflowManager implements WorkflowManagerInterface
{
    protected SecurityManager $security;
    protected ValidationService $validator;
    protected NotificationManager $notifications;

    public function transitionContent(Content $content, string $newState, SecurityContext $context): WorkflowResult
    {
        return $this->protection->executeProtectedOperation(
            fn() => $this->processWorkflowTransition($content, $newState),
            $context,
            'workflow.transition'
        );
    }

    protected function processWorkflowTransition(Content $content, string $newState): WorkflowResult
    {
        DB::beginTransaction();
        try {
            $transition = $this->validateTransition($content, $newState);
            $this->executeTransition($content, $transition);
            $this->notifyStakeholders($content, $transition);
            
            DB::commit();
            return new WorkflowResult($transition);
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw new WorkflowException("Workflow transition failed: {$e->getMessage()}", 0, $e);
        }
    }
}
