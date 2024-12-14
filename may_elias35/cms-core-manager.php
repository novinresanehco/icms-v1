<?php

namespace App\Core\CMS;

use App\Core\Security\SecurityManager;
use App\Core\Validation\ValidationService;
use App\Core\Cache\CacheManager;
use App\Core\Audit\AuditLogger;

class CMSCoreManager implements CMSManagerInterface
{
    private SecurityManager $security;
    private ValidationService $validator;
    private CacheManager $cache;
    private AuditLogger $audit;

    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        CacheManager $cache,
        AuditLogger $audit
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->cache = $cache;
        $this->audit = $audit;
    }

    public function handleContentOperation(ContentOperation $operation): OperationResult
    {
        DB::beginTransaction();
        
        try {
            // Validate operation
            $this->validateContentOperation($operation);
            
            // Execute with security
            $result = $this->executeSecureOperation($operation);
            
            // Update cache
            $this->updateCache($operation, $result);
            
            DB::commit();
            $this->audit->logContentOperation($operation, $result);
            
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleOperationFailure($e, $operation);
            throw $e;
        }
    }

    private function validateContentOperation(ContentOperation $operation): void
    {
        if (!$this->validator->validateContent($operation)) {
            throw new ContentValidationException('Invalid content operation');
        }

        if (!$this->security->verifyContentAccess($operation)) {
            throw new ContentAccessException('Content access denied');
        }
    }

    private function executeSecureOperation(ContentOperation $operation): OperationResult
    {
        $securityContext = $this->createSecurityContext($operation);
        
        return $this->security->executeCriticalOperation(
            new SecureContentOperation($operation, $securityContext)
        );
    }

    private function updateCache(ContentOperation $operation, OperationResult $result): void
    {
        $this->cache->updateContent(
            $operation->getContentKey(),
            $result->getData(),
            $operation->getCacheMetadata()
        );
    }

    private function handleOperationFailure(\Exception $e, ContentOperation $operation): void
    {
        $this->audit->logFailure($e, [
            'operation' => $operation->getId(),
            'content' => $operation->getContentKey(),
            'user' => $operation->getUserContext(),
            'timestamp' => now()
        ]);
        
        $this->cache->invalidateContent($operation->getContentKey());
    }

    private function createSecurityContext(ContentOperation $operation): SecurityContext
    {
        return new SecurityContext(
            $operation->getUserContext(),
            $operation->getContentPermissions(),
            $operation->getSecurityMetadata()
        );
    }
}
