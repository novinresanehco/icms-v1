<?php

namespace App\Core\CMS;

use App\Core\Security\CoreSecuritySystem;
use App\Core\Interfaces\{
    ContentManagerInterface,
    ValidationInterface,
    CacheManagerInterface, 
    SecurityContextInterface
};
use App\Core\Models\{
    Content,
    ContentMetadata,
    ContentVersion
};
use App\Core\Exceptions\{
    ContentValidationException,
    SecurityException,
    StorageException
};
use Psr\Log\LoggerInterface;
use Illuminate\Support\Facades\DB;

/**
 * Core Content Management System
 * Handles all content operations with comprehensive security and validation
 */
class ContentManager implements ContentManagerInterface 
{
    private CoreSecuritySystem $security;
    private ValidationInterface $validator;
    private CacheManagerInterface $cache;
    private LoggerInterface $logger;
    private ContentRepository $repository;

    // Critical configuration
    private const MAX_CONTENT_SIZE = 10485760; // 10MB
    private const CACHE_TTL = 3600; // 1 hour
    private const VERSION_LIMIT = 10;
    private const ALLOWED_MIME_TYPES = [
        'text/html',
        'text/plain',
        'application/json',
        'application/xml'
    ];

    public function __construct(
        CoreSecuritySystem $security,
        ValidationInterface $validator,
        CacheManagerInterface $cache,
        LoggerInterface $logger,
        ContentRepository $repository
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->cache = $cache;
        $this->logger = $logger;
        $this->repository = $repository;
    }

    /**
     * Create new content with comprehensive validation and security
     * 
     * @throws ContentValidationException
     * @throws SecurityException
     * @throws StorageException
     */
    public function createContent(array $data, SecurityContextInterface $context): Content
    {
        return $this->security->executeCriticalOperation(
            $context,
            function() use ($data) {
                // Validate input
                $validatedData = $this->validateContentInput($data);

                // Create content in transaction
                return DB::transaction(function() use ($validatedData) {
                    // Store content
                    $content = $this->repository->create($validatedData);

                    // Create initial version
                    $this->createContentVersion($content);

                    // Generate metadata 
                    $this->generateContentMetadata($content);

                    // Set cache
                    $this->setCacheContent($content);

                    // Log creation
                    $this->logContentCreation($content);

                    return $content;
                });
            }
        );
    }

    /**
     * Update existing content with versioning
     * 
     * @throws ContentValidationException 
     * @throws SecurityException
     * @throws StorageException
     */
    public function updateContent(int $id, array $data, SecurityContextInterface $context): Content
    {
        return $this->security->executeCriticalOperation(
            $context, 
            function() use ($id, $data) {
                // Validate update data
                $validatedData = $this->validateContentInput($data);

                // Execute update in transaction
                return DB::transaction(function() use ($id, $validatedData) {
                    // Get current content
                    $content = $this->repository->find($id);
                    
                    // Create new version
                    $this->createContentVersion($content);

                    // Update content
                    $content = $this->repository->update($id, $validatedData);

                    // Update metadata
                    $this->updateContentMetadata($content);

                    // Update cache
                    $this->setCacheContent($content);

                    // Log update
                    $this->logContentUpdate($content);

                    return $content;
                });
            }
        );
    }

    /**
     * Delete content with security verification
     * 
     * @throws SecurityException
     * @throws StorageException
     */
    public function deleteContent(int $id, SecurityContextInterface $context): void
    {
        $this->security->executeCriticalOperation(
            $context,
            function() use ($id) {
                DB::transaction(function() use ($id) {
                    // Get content
                    $content = $this->repository->find($id);

                    // Delete content
                    $this->repository->delete($id);

                    // Clear cache
                    $this->clearContentCache($content);

                    // Log deletion
                    $this->logContentDeletion($content);
                });
            }
        );
    }

    /**
     * Get content by ID with caching
     */
    public function getContent(int $id, SecurityContextInterface $context): ?Content
    {
        return $this->security->executeCriticalOperation(
            $context,
            function() use ($id) {
                return $this->cache->remember(
                    $this->getContentCacheKey($id),
                    self::CACHE_TTL,
                    fn() => $this->repository->find($id)
                );
            }
        );
    }

    /**
     * Validate content input data
     *
     * @throws ContentValidationException
     */
    protected function validateContentInput(array $data): array 
    {
        // Validate size
        if (strlen($data['content']) > self::MAX_CONTENT_SIZE) {
            throw new ContentValidationException('Content exceeds maximum size limit');
        }

        // Validate MIME type
        if (!in_array($data['mime_type'], self::ALLOWED_MIME_TYPES)) {
            throw new ContentValidationException('Invalid content MIME type');
        }

        // Validate structure and format
        if (!$this->validator->validateContentStructure($data)) {
            throw new ContentValidationException('Invalid content structure');
        }

        return $this->validator->sanitizeContent($data);
    }

    /**
     * Create new content version
     */
    protected function createContentVersion(Content $content): ContentVersion
    {
        // Create version
        $version = new ContentVersion([
            'content_id' => $content->id,
            'content' => $content->content,
            'metadata' => $content->metadata,
            'version' => $this->getNextVersionNumber($content)
        ]);

        // Prune old versions
        $this->pruneContentVersions($content);

        return $this->repository->createVersion($version);
    }

    /**
     * Generate content metadata
     */
    protected function generateContentMetadata(Content $content): ContentMetadata
    {
        $metadata = new ContentMetadata([
            'content_id' => $content->id,
            'size' => strlen($content->content),
            'mime_type' => $content->mime_type,
            'hash' => hash('sha256', $content->content),
            'created_at' => now(),
            'updated_at' => now()
        ]);

        return $this->repository->createMetadata($metadata);
    }

    /**
     * Set content in cache
     */
    protected function setCacheContent(Content $content): void
    {
        $this->cache->put(
            $this->getContentCacheKey($content->id),
            $content,
            self::CACHE_TTL
        );
    }

    /**
     * Clear content from cache
     */
    protected function clearContentCache(Content $content): void
    {
        $this->cache->forget($this->getContentCacheKey($content->id));
    }

    /**
     * Get cache key for content
     */
    protected function getContentCacheKey(int $id): string
    {
        return "content.{$id}";
    }

    /**
     * Get next version number for content
     */
    protected function getNextVersionNumber(Content $content): int
    {
        return $this->repository->getLatestVersionNumber($content->id) + 1;
    }

    /**
     * Prune old content versions
     */
    protected function pruneContentVersions(Content $content): void
    {
        $versions = $this->repository->getVersions($content->id);
        
        if (count($versions) >= self::VERSION_LIMIT) {
            $this->repository->deleteOldestVersion($content->id);
        }
    }

    /**
     * Log content creation
     */
    protected function logContentCreation(Content $content): void
    {
        $this->logger->info('Content created', [
            'content_id' => $content->id,
            'type' => $content->type,
            'size' => strlen($content->content)
        ]);
    }

    /**
     * Log content update
     */
    protected function logContentUpdate(Content $content): void 
    {
        $this->logger->info('Content updated', [
            'content_id' => $content->id,
            'type' => $content->type,
            'size' => strlen($content->content)
        ]);
    }

    /**
     * Log content deletion
     */
    protected function logContentDeletion(Content $content): void
    {
        $this->logger->info('Content deleted', [
            'content_id' => $content->id,
            'type' => $content->type
        ]);
    }
}
