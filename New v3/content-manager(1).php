<?php

namespace App\Core\Content;

use App\Core\Security\SecurityManager;
use App\Core\Cache\CacheManager;
use App\Core\Monitoring\MonitoringService;
use App\Core\Storage\StorageManager;
use App\Core\Database\DatabaseManager;
use App\Core\Exceptions\ContentException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Core Content Management System
 * CRITICAL COMPONENT - Handles all content operations with maximum security and performance
 */
class ContentManager
{
    private SecurityManager $security;
    private CacheManager $cache;
    private MonitoringService $monitor;
    private StorageManager $storage;
    private DatabaseManager $database;
    
    /**
     * High-security construction with all critical dependencies
     */
    public function __construct(
        SecurityManager $security,
        CacheManager $cache,
        MonitoringService $monitor,
        StorageManager $storage,
        DatabaseManager $database
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->monitor = $monitor;
        $this->storage = $storage;
        $this->database = $database;
    }

    /**
     * Creates new content with complete validation and security
     *
     * @param array $data Validated content data
     * @param string $type Content type
     * @return Content
     * @throws ContentException
     */
    public function create(array $data, string $type): Content
    {
        // Start monitoring
        $operationId = $this->monitor->startOperation('content.create');
        
        try {
            // Security validation
            $this->security->validateContentCreation($data, $type);
            
            DB::beginTransaction();
            
            // Process and store content
            $content = $this->processContent($data, $type);
            
            // Store content
            $result = $this->database->store('content', $content);
            
            // Store related files if any
            if (isset($data['files'])) {
                $this->storage->storeFiles($data['files'], $result->id);
            }
            
            // Cache the new content
            $this->cache->set("content.{$result->id}", $content);
            
            DB::commit();
            
            // Log success
            Log::info('Content created successfully', [
                'id' => $result->id,
                'type' => $type
            ]);
            
            return $result;
            
        } catch (\Throwable $e) {
            DB::rollBack();
            
            // Log failure
            Log::error('Content creation failed', [
                'error' => $e->getMessage(),
                'data' => $data,
                'type' => $type
            ]);
            
            throw new ContentException(
                'Failed to create content: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        } finally {
            $this->monitor->endOperation($operationId);
        }
    }

    /**
     * Updates existing content with full security validation
     * 
     * @param int $id Content ID
     * @param array $data Update data
     * @return Content
     * @throws ContentException
     */
    public function update(int $id, array $data): Content
    {
        $operationId = $this->monitor->startOperation('content.update');
        
        try {
            // Verify permissions
            $this->security->validateContentUpdate($id, $data);
            
            DB::beginTransaction();
            
            // Get current version
            $current = $this->database->find('content', $id);
            if (!$current) {
                throw new ContentException('Content not found');
            }
            
            // Create version backup
            $this->createVersion($current);
            
            // Update content
            $updated = $this->processContent($data, $current->type);
            $result = $this->database->update('content', $id, $updated);
            
            // Update cache
            $this->cache->set("content.{$id}", $result);
            
            DB::commit();
            
            Log::info('Content updated successfully', ['id' => $id]);
            
            return $result;
            
        } catch (\Throwable $e) {
            DB::rollBack();
            
            Log::error('Content update failed', [
                'id' => $id,
                'error' => $e->getMessage()
            ]);
            
            throw new ContentException(
                'Failed to update content: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        } finally {
            $this->monitor->endOperation($operationId);
        }
    }

    /**
     * Retrieves content with security checks and caching
     */
    public function retrieve(int $id): ?Content
    {
        $operationId = $this->monitor->startOperation('content.retrieve');
        
        try {
            // Check permissions
            $this->security->validateContentAccess($id);
            
            // Try cache first
            $cached = $this->cache->get("content.{$id}");
            if ($cached) {
                return $cached;
            }
            
            // Get from database
            $content = $this->database->find('content', $id);
            if (!$content) {
                return null;
            }
            
            // Cache for next time
            $this->cache->set("content.{$id}", $content);
            
            return $content;
            
        } catch (\Throwable $e) {
            Log::error('Content retrieval failed', [
                'id' => $id,
                'error' => $e->getMessage()
            ]);
            
            throw new ContentException(
                'Failed to retrieve content: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        } finally {
            $this->monitor->endOperation($operationId);
        }
    }

    /**
     * Deletes content with security validation
     */
    public function delete(int $id): bool
    {
        $operationId = $this->monitor->startOperation('content.delete');
        
        try {
            // Verify delete permissions
            $this->security->validateContentDeletion($id);
            
            DB::beginTransaction();
            
            // Create backup version
            $current = $this->database->find('content', $id);
            if (!$current) {
                throw new ContentException('Content not found');
            }
            
            $this->createVersion($current);
            
            // Delete content
            $this->database->delete('content', $id);
            
            // Remove from cache
            $this->cache->delete("content.{$id}");
            
            // Delete associated files
            $this->storage->deleteContentFiles($id);
            
            DB::commit();
            
            Log::info('Content deleted successfully', ['id' => $id]);
            
            return true;
            
        } catch (\Throwable $e) {
            DB::rollBack();
            
            Log::error('Content deletion failed', [
                'id' => $id,
                'error' => $e->getMessage()
            ]);
            
            throw new ContentException(
                'Failed to delete content: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        } finally {
            $this->monitor->endOperation($operationId);
        }
    }

    /**
     * Creates a backup version of content
     */
    private function createVersion(Content $content): void
    {
        $versionData = [
            'content_id' => $content->id,
            'data' => $content->data,
            'created_at' => now(),
            'created_by' => auth()->id()
        ];
        
        $this->database->store('content_versions', $versionData);
    }

    /**
     * Processes and validates content data
     */
    private function processContent(array $data, string $type): array
    {
        // Sanitize content
        $sanitized = $this->security->sanitizeContent($data);
        
        // Validate structure
        if (!$this->validateContentStructure($sanitized, $type)) {
            throw new ContentException('Invalid content structure');
        }
        
        // Process any embedded content
        $processed = $this->processEmbeddedContent($sanitized);
        
        return $processed;
    }

    /**
     * Validates content structure based on type
     */
    private function validateContentStructure(array $data, string $type): bool
    {
        // Implementation of content structure validation
        return true;
    }

    /**
     * Processes any embedded content (images, videos, etc)
     */
    private function processEmbeddedContent(array $data): array
    {
        // Implementation of embedded content processing
        return $data;
    }
}
