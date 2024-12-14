<?php

namespace App\Core\CMS;

use App\Core\Security\SecurityManager;
use App\Core\CMS\Content\ContentService;
use App\Core\CMS\Media\MediaService;
use App\Core\Cache\CacheManager;
use App\Core\Audit\AuditLogger;

class CoreCMSManager implements CMSManagerInterface
{
    private SecurityManager $security;
    private ContentService $content;
    private MediaService $media;
    private CacheManager $cache;
    private AuditLogger $audit;

    public function __construct(
        SecurityManager $security,
        ContentService $content,
        MediaService $media,
        CacheManager $cache,
        AuditLogger $audit
    ) {
        $this->security = $security;
        $this->content = $content;
        $this->media = $media;
        $this->cache = $cache;
        $this->audit = $audit;
    }

    public function executeContentOperation(ContentOperation $operation): OperationResult
    {
        DB::beginTransaction();

        try {
            $this->validateContentOperation($operation);
            
            $result = $this->executeProtectedContent($operation);
            
            $this->validateResult($result);
            $this->updateCache($result);
            
            DB::commit();
            $this->audit->logContentOperation($operation);
            
            return $result;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFailure($e, $operation);
            throw $e;
        }
    }

    private function validateContentOperation(ContentOperation $operation): void
    {
        $this->security->validateOperation($operation);
        
        if (!$this->content->validateOperation($operation)) {
            throw new ContentValidationException('Invalid content operation');
        }

        if (!$this->validateContentIntegrity($operation)) {
            throw new IntegrityException('Content integrity check failed');
        }
    }

    private function executeProtectedContent(ContentOperation $operation): OperationResult 
    {
        $context = $this->createContentContext();

        try {
            return $this->content->execute($operation, $context);
        } catch (\Exception $e) {
            $this->handleExecutionFailure($e);
            throw $e;
        }
    }

    private function validateResult(OperationResult $result): void
    {
        if (!$this->content->validateResult($result)) {
            throw new ValidationException('Invalid content result');
        }

        if (!$this->validateResultIntegrity($result)) {
            throw new IntegrityException('Result integrity check failed'); 
        }
    }

    private function updateCache(OperationResult $result): void
    {
        $this->cache->invalidate($result->getCacheKeys());
        $this->cache->store($result->getCacheData());
    }

    private function validateContentIntegrity(ContentOperation $operation): bool
    {
        return $this->content->verifyIntegrity($operation) &&
               $this->security->verifyIntegrity($operation);
    }

    private function validateResultIntegrity(OperationResult $result): bool
    {
        return $this->content->verifyResultIntegrity($result) &&
               $this->security->verifyResultIntegrity($result);
    }

    private function createContentContext(): ContentContext
    {
        return new ContentContext(
            $this->security,
            $this->content,
            $this->media
        );
    }

    private function handleExecutionFailure(\Exception $e): void
    {
        $this->audit->logExecutionFailure($e);
        $this->cache->purge();
    }

    private function handleFailure(\Exception $e, ContentOperation $operation): void
    {
        $this->audit->logFailure($operation, $e);
        $this->content->rollback($operation);
        $this->cache->purge();

        if ($e instanceof SecurityException) {
            $this->security->handleSecurityFailure($e);
        }
    }
}
