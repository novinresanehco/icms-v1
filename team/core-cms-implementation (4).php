<?php

namespace App\Core\CMS;

use App\Core\Security\SecurityManager;
use App\Core\Cache\CacheManager;
use App\Core\Monitor\PerformanceMonitor;
use App\Core\Validation\ValidationService;
use Illuminate\Support\Facades\DB;

/**
 * Core CMS implementation with critical security and performance controls
 */
class ContentManager implements CriticalSystemInterface
{
    private SecurityManager $security;
    private CacheManager $cache;
    private PerformanceMonitor $monitor;
    private ValidationService $validator;
    private Repository $repository;

    public function __construct(
        SecurityManager $security,
        CacheManager $cache,
        PerformanceMonitor $monitor,
        ValidationService $validator,
        Repository $repository
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->monitor = $monitor;
        $this->validator = $validator;
        $this->repository = $repository;
    }

    /**
     * Execute critical CMS operation with full protection
     */
    public function executeOperation(ContentOperation $operation): OperationResult
    {
        // Start performance monitoring
        $tracking = $this->monitor->startOperation($operation);
        
        try {
            // Execute in security context
            return $this->security->executeProtected(function() use ($operation) {
                DB::beginTransaction();
                
                try {
                    // Pre-execution validation
                    $this->validateOperation($operation);
                    
                    // Execute core operation
                    $result = $this->executeCore($operation);
                    
                    // Post-execution verification
                    $this->verifyResult($result);
                    
                    // Update cache and commit
                    $this->updateCache($operation, $result);
                    DB::commit();
                    
                    return $result;
                    
                } catch (\Throwable $e) {
                    DB::rollBack();
                    throw $e;
                }
            });
        } catch (\Throwable $e) {
            $this->handleFailure($e, $operation);
            throw $e;
        } finally {
            $this->monitor->endOperation($tracking);
        }
    }

    /**
     * Core operation execution with type validation
     */
    private function executeCore(ContentOperation $operation): OperationResult
    {
        return match($operation->getType()) {
            OperationType::CREATE => $this->createContent($operation),
            OperationType::UPDATE => $this->updateContent($operation),
            OperationType::DELETE => $this->deleteContent($operation),
            OperationType::PUBLISH => $this->publishContent($operation),
            default => throw new InvalidOperationException()
        };
    }

    /**
     * Create content with validation and security checks
     */
    private function createContent(ContentOperation $operation): OperationResult
    {
        // Validate content data
        $data = $this->validator->validateContent(
            $operation->getData(),
            ContentValidationRules::CREATE
        );

        // Check for duplicates
        if ($this->repository->exists($data['slug'])) {
            throw new DuplicateContentException();
        }

        // Create with security context
        $content = $this->repository->create($data);

        // Verify creation
        if (!$content->exists) {
            throw new ContentCreationException();
        }

        return new OperationResult($content, OperationStatus::SUCCESS);
    }

    /**
     * Update content with version control
     */
    private function updateContent(ContentOperation $operation): OperationResult
    {
        // Validate update data
        $data = $this->validator->validateContent(
            $operation->getData(),
            ContentValidationRules::UPDATE
        );

        // Create version backup
        $this->createContentVersion($operation->getId());

        // Perform update
        $content = $this->repository->update(
            $operation->getId(),
            $data
        );

        // Verify update
        if (!$content->wasChanged()) {
            throw new ContentUpdateException();
        }

        return new OperationResult($content, OperationStatus::SUCCESS);
    }

    /**
     * Delete content with safeguards
     */
    private function deleteContent(ContentOperation $operation): OperationResult
    {
        // Create backup before deletion
        $this->createContentBackup($operation->getId());

        // Perform soft delete
        $result = $this->repository->softDelete($operation->getId());

        // Verify deletion
        if (!$result) {
            throw new ContentDeletionException();
        }

        return new OperationResult(null, OperationStatus::SUCCESS);
    }

    /**
     * Publish content with workflow validation
     */
    private function publishContent(ContentOperation $operation): OperationResult
    {
        // Validate publication status
        if (!$this->validator->canPublish($operation->getId())) {
            throw new PublicationValidationException();
        }

        // Perform publication
        $content = $this->repository->publish($operation->getId());

        // Verify publication
        if (!$content->isPublished()) {
            throw new PublicationFailedException();
        }

        return new OperationResult($content, OperationStatus::SUCCESS);
    }

    /**
     * Pre-execution validation
     */
    private function validateOperation(ContentOperation $operation): void
    {
        // Validate operation type
        if (!$this->validator->isValidOperation($operation)) {
            throw new InvalidOperationException();
        }

        // Validate permissions
        if (!$this->security->hasPermission($operation)) {
            throw new PermissionDeniedException();
        }

        // Validate system state
        if (!$this->monitor->isSystemHealthy()) {
            throw new SystemNotReadyException();
        }
    }

    /**
     * Post-execution verification
     */
    private function verifyResult(OperationResult $result): void
    {
        // Verify result integrity
        if (!$this->validator->verifyResult($result)) {
            throw new ResultValidationException();
        }

        // Verify system constraints
        if (!$this->monitor->verifySystemConstraints()) {
            throw new SystemConstraintException();
        }
    }

    /**
     * Cache management
     */
    private function updateCache(ContentOperation $operation, OperationResult $result): void
    {
        // Invalidate affected cache
        $this->cache->invalidateKeys(
            $this->getCacheKeys($operation)
        );

        // Update cache if needed
        if ($operation->shouldCache()) {
            $this->cache->store(
                $this->getCacheKey($result->content),
                $result->content,
                CacheConfig::CONTENT_TTL
            );
        }
    }

    /**
     * Failure handling with logging
     */
    private function handleFailure(\Throwable $e, ContentOperation $operation): void
    {
        // Log detailed error
        $this->monitor->logError($e, [
            'operation' => $operation->toArray(),
            'trace' => $e->getTraceAsString()
        ]);

        // Alert if critical
        if ($this->isCriticalError($e)) {
            $this->monitor->sendCriticalAlert($e);
        }
    }
}
