<?php

namespace App\Core\CMS;

use App\Core\Security\SecurityManager;
use App\Core\Contracts\CMSManagerInterface;
use App\Core\Exceptions\CMSException;

/**
 * Core CMS Manager - Handles all critical CMS operations
 * Integrates with SecurityManager for protected operations
 */
class CMSManager implements CMSManagerInterface 
{
    protected SecurityManager $security;
    protected ContentRepository $content;
    protected CacheManager $cache;
    protected ValidatorService $validator;
    protected MetricsCollector $metrics;

    public function __construct(
        SecurityManager $security,
        ContentRepository $content,
        CacheManager $cache,
        ValidatorService $validator,
        MetricsCollector $metrics
    ) {
        $this->security = $security;
        $this->content = $content;
        $this->cache = $cache;
        $this->validator = $validator;
        $this->metrics = $metrics;
    }

    /**
     * Create new content with security validation
     * @throws CMSException
     */
    public function createContent(array $data): array
    {
        return $this->security->executeSecureOperation(
            'content.create',
            function() use ($data) {
                // Validate content structure
                $validatedData = $this->validator->validateContent($data);
                
                // Store content
                $content = $this->content->create($validatedData);
                
                // Clear relevant caches
                $this->cache->invalidateContentCaches($content['type']);
                
                // Track metrics
                $this->metrics->trackContentCreation($content['type']);
                
                return $content;
            }
        );
    }

    /**
     * Retrieve content with caching and security checks
     */
    public function getContent(string $id): array
    {
        return $this->cache->remember(
            "content.$id",
            function() use ($id) {
                return $this->security->executeSecureOperation(
                    'content.read',
                    function() use ($id) {
                        return $this->content->find($id);
                    }
                );
            }
        );
    }

    /**
     * Update existing content with versioning
     */
    public function updateContent(string $id, array $data): array
    {
        return $this->security->executeSecureOperation(
            'content.update',
            function() use ($id, $data) {
                // Create content version
                $this->content->createVersion($id);
                
                // Update content
                $validatedData = $this->validator->validateContent($data);
                $content = $this->content->update($id, $validatedData);
                
                // Clear caches
                $this->cache->invalidateContentCaches($content['type']);
                
                return $content;
            }
        );
    }

    /**
     * Delete content with security verification
     */
    public function deleteContent(string $id): bool
    {
        return $this->security->executeSecureOperation(
            'content.delete',
            function() use ($id) {
                // Create backup version
                $this->content->createVersion($id);
                
                // Soft delete content
                $success = $this->content->softDelete($id);
                
                if ($success) {
                    // Clear caches
                    $this->cache->invalidateContentCaches($id);
                    
                    // Track metrics
                    $this->metrics->trackContentDeletion($id);
                }
                
                return $success;
            }
        );
    }

    /**
     * List content with pagination and filters
     */
    public function listContent(array $filters = [], int $page = 1): array
    {
        return $this->security->executeSecureOperation(
            'content.list',
            function() use ($filters, $page) {
                return $this->content->paginate($filters, $page);
            }
        );
    }

    /**
     * Search content with security filtering
     */
    public function searchContent(string $query, array $filters = []): array
    {
        return $this->security->executeSecureOperation(
            'content.search',
            function() use ($query, $filters) {
                return $this->content->search($query, $filters);
            }
        );
    }

    /**
     * Restore content version
     */
    public function restoreVersion(string $id, string $versionId): array
    {
        return $this->security->executeSecureOperation(
            'content.restore',
            function() use ($id, $versionId) {
                // Validate version exists
                $version = $this->content->getVersion($id, $versionId);
                
                if (!$version) {
                    throw new CMSException('Version not found');
                }
                
                // Restore content
                $content = $this->content->restore($id, $versionId);
                
                // Clear caches
                $this->cache->invalidateContentCaches($content['type']);
                
                return $content;
            }
        );
    }
}
