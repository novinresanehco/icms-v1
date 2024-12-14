<?php

namespace App\Core\CMS;

use Illuminate\Support\Facades\{DB, Cache, Log};
use App\Core\Security\SecurityManager;
use App\Core\CMS\Repositories\ContentRepository;
use App\Core\CMS\Events\{ContentCreatedEvent, ContentUpdatedEvent, ContentDeletedEvent};
use App\Core\CMS\Exceptions\{ContentException, ValidationException};

/**
 * Core CMS Management System
 * Handles all critical content operations with comprehensive protection
 */
class ContentManager implements ContentManagerInterface
{
    private SecurityManager $security;
    private ContentRepository $repository;
    private ValidationService $validator;
    private CacheManager $cache;
    private AuditLogger $auditLogger;

    public function __construct(
        SecurityManager $security,
        ContentRepository $repository,
        ValidationService $validator,
        CacheManager $cache,
        AuditLogger $auditLogger
    ) {
        $this->security = $security;
        $this->repository = $repository;
        $this->validator = $validator;
        $this->cache = $cache;
        $this->auditLogger = $auditLogger;
    }

    /**
     * Create new content with full validation and security
     *
     * @throws ContentException
     * @throws ValidationException
     */
    public function createContent(array $data, SecurityContext $context): ContentResult
    {
        return $this->security->executeCriticalOperation(
            new CreateContentOperation($data),
            $context,
            function() use ($data) {
                // Validate content data
                $validatedData = $this->validator->validateContent($data);
                
                // Process and store content
                $content = $this->repository->create($validatedData);
                
                // Cache the new content
                $this->cache->putContent($content);
                
                // Log creation
                $this->auditLogger->logContentCreation($content);
                
                // Fire events
                event(new ContentCreatedEvent($content));
                
                return new ContentResult($content);
            }
        );
    }

    /**
     * Update existing content with validation
     */
    public function updateContent(int $id, array $data, SecurityContext $context): ContentResult
    {
        return $this->security->executeCriticalOperation(
            new UpdateContentOperation($id, $data),
            $context,
            function() use ($id, $data) {
                // Validate content exists
                $content = $this->repository->findOrFail($id);
                
                // Validate update data
                $validatedData = $this->validator->validateContent($data);
                
                // Update content
                $updatedContent = $this->repository->update($content, $validatedData);
                
                // Update cache
                $this->cache->putContent($updatedContent);
                
                // Log update
                $this->auditLogger->logContentUpdate($updatedContent);
                
                // Fire events
                event(new ContentUpdatedEvent($updatedContent));
                
                return new ContentResult($updatedContent);
            }
        );
    }

    /**
     * Delete content with security verification
     */
    public function deleteContent(int $id, SecurityContext $context): bool
    {
        return $this->security->executeCriticalOperation(
            new DeleteContentOperation($id),
            $context,
            function() use ($id) {
                // Verify content exists
                $content = $this->repository->findOrFail($id);
                
                // Delete content
                $this->repository->delete($content);
                
                // Clear cache
                $this->cache->forgetContent($id);
                
                // Log deletion
                $this->auditLogger->logContentDeletion($content);
                
                // Fire events
                event(new ContentDeletedEvent($content));
                
                return true;
            }
        );
    }

    /**
     * Retrieve content with caching
     */
    public function getContent(int $id, SecurityContext $context): ContentResult
    {
        // Check cache first
        if ($cached = $this->cache->getContent($id)) {
            return new ContentResult($cached);
        }

        return $this->security->executeCriticalOperation(
            new GetContentOperation($id),
            $context,
            function() use ($id) {
                // Retrieve content
                $content = $this->repository->findOrFail($id);
                
                // Cache content
                $this->cache->putContent($content);
                
                // Log access
                $this->auditLogger->logContentAccess($content);
                
                return new ContentResult($content);
            }
        );
    }

    /**
     * List content with pagination and filtering
     */
    public function listContent(array $filters, SecurityContext $context): ContentListResult
    {
        return $this->security->executeCriticalOperation(
            new ListContentOperation($filters),
            $context,
            function() use ($filters) {
                // Validate filters
                $validatedFilters = $this->validator->validateFilters($filters);
                
                // Get cached list if available
                $cacheKey = $this->generateListCacheKey($validatedFilters);
                if ($cached = $this->cache->get($cacheKey)) {
                    return $cached;
                }
                
                // Retrieve content list
                $contents = $this->repository->list($validatedFilters);
                
                // Cache results
                $this->cache->put($cacheKey, $contents, 300); // 5 minutes
                
                return new ContentListResult($contents);
            }
        );
    }

    /**
     * Generate cache key for content list
     */
    private function generateListCacheKey(array $filters): string
    {
        return 'content_list:' . md5(serialize($filters));
    }

    /**
     * Execute bulk operation on content
     */
    public function bulkOperation(array $ids, string $operation, SecurityContext $context): BulkOperationResult
    {
        return $this->security->executeCriticalOperation(
            new BulkContentOperation($ids, $operation),
            $context,
            function() use ($ids, $operation) {
                $results = [];
                $failed = [];
                
                foreach ($ids as $id) {
                    try {
                        $results[$id] = $this->executeSingleOperation($id, $operation);
                    } catch (\Exception $e) {
                        $failed[$id] = $e->getMessage();
                        Log::error('Bulk operation failed for content', [
                            'id' => $id,
                            'operation' => $operation,
                            'error' => $e->getMessage()
                        ]);
                    }
                }
                
                return new BulkOperationResult($results, $failed);
            }
        );
    }

    /**
     * Execute single content operation
     */
    private function executeSingleOperation(int $id, string $operation)
    {
        switch ($operation) {
            case 'publish':
                return $this->publishContent($id);
            case 'unpublish':
                return $this->unpublishContent($id);
            case 'archive':
                return $this->archiveContent($id);
            default:
                throw new ContentException("Unknown operation: {$operation}");
        }
    }
}
