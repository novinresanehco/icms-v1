<?php

namespace App\Core\Content;

/**
 * Core content management implementation with security integration
 */
class ContentManager implements ContentManagerInterface
{
    private SecurityManager $security;
    private ContentRepository $repository;
    private CacheManager $cache;
    private ValidationService $validator;
    private AuditLogger $logger;

    public function __construct(
        SecurityManager $security,
        ContentRepository $repository,
        CacheManager $cache,
        ValidationService $validator,
        AuditLogger $logger
    ) {
        $this->security = $security;
        $this->repository = $repository;
        $this->cache = $cache;
        $this->validator = $validator;
        $this->logger = $logger;
    }

    public function createContent(array $data, SecurityContext $context): Content
    {
        // Create operation context
        $operation = new CreateContentOperation($data);
        
        // Execute with security controls
        return $this->security->executeCriticalOperation(
            $operation,
            $context
        );
    }

    public function updateContent(int $id, array $data, SecurityContext $context): Content
    {
        // Create operation context
        $operation = new UpdateContentOperation($id, $data);
        
        // Execute with security
        $result = $this->security->executeCriticalOperation(
            $operation,
            $context
        );

        // Invalidate cache
        $this->cache->invalidate("content.$id");
        
        return $result;
    }

    public function getContent(int $id, SecurityContext $context): Content
    {
        return $this->cache->remember(
            "content.$id",
            config('cache.ttl'),
            function() use ($id, $context) {
                // Create operation context
                $operation = new GetContentOperation($id);
                
                // Execute with security
                return $this->security->executeCriticalOperation(
                    $operation,
                    $context
                );
            }
        );
    }

    public function deleteContent(int $id, SecurityContext $context): bool
    {
        // Create operation context
        $operation = new DeleteContentOperation($id);
        
        // Execute with security
        $result = $this->security->executeCriticalOperation(
            $operation,
            $context
        );

        // Invalidate cache
        $this->cache->invalidate("content.$id");
        
        return $result;
    }

    public function listContent(array $criteria, SecurityContext $context): Collection
    {
        $cacheKey = $this->generateListCacheKey($criteria);
        
        return $this->cache->remember(
            $cacheKey,
            config('cache.ttl'),
            function() use ($criteria, $context) {
                // Create operation context
                $operation = new ListContentOperation($criteria);
                
                // Execute with security
                return $this->security->executeCriticalOperation(
                    $operation,
                    $context
                );
            }
        );
    }

    private function generateListCacheKey(array $criteria): string
    {
        return 'content.list.' . md5(serialize($criteria));
    }
}
