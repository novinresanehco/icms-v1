<?php

namespace App\Core\Media;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Monitoring\OperationMonitorInterface;
use App\Core\Cache\CacheManagerInterface;
use App\Core\Storage\StorageManagerInterface;
use App\Core\Validation\ValidationServiceInterface;
use App\Core\Exception\{
    AccessDeniedException,
    NotFoundException,
    ValidationException,
    StorageException
};

/**
 * Critical media storage service with comprehensive security and monitoring
 */
class MediaStorageService implements MediaStorageInterface
{
    private SecurityManagerInterface $security;
    private OperationMonitorInterface $monitor;
    private CacheManagerInterface $cache;
    private StorageManagerInterface $store;
    private ValidationServiceInterface $validator;

    public function __construct(
        SecurityManagerInterface $security,
        OperationMonitorInterface $monitor,
        CacheManagerInterface $cache,
        StorageManagerInterface $store,
        ValidationServiceInterface $validator
    ) {
        $this->security = $security;
        $this->monitor = $monitor;
        $this->cache = $cache;
        $this->store = $store;
        $this->validator = $validator;
    }

    /**
     * Retrieve media with comprehensive security and validation
     *
     * @param string $fileId Media file identifier
     * @param string $userId Requesting user identifier
     * @throws AccessDeniedException If user lacks permission
     * @throws NotFoundException If media not found
     * @throws ValidationException If validation fails
     * @throws StorageException If storage operation fails
     * @return array Media data
     */
    public function retrieveMedia(string $fileId, string $userId): array
    {
        // Start monitored operation
        $operationId = $this->monitor->startOperation('media_retrieve', [
            'file_id' => $fileId,
            'user_id' => $userId
        ]);

        try {
            // Validate inputs
            $this->validator->validateId($fileId);
            $this->validator->validateId($userId);

            // Verify security context
            $this->security->validateAccess($userId, 'media:retrieve', $fileId);

            // Try cache with security check
            $cacheKey = $this->generateSecureCacheKey($fileId, $userId);
            if ($cached = $this->cache->get($cacheKey)) {
                $this->monitor->recordCacheHit($operationId);
                return $this->validator->validateMediaData($cached);
            }

            // Get from storage with security wrapper
            $media = $this->store->retrieveSecure($fileId, $userId);
            if (!$media) {
                throw new NotFoundException('Media not found: ' . $fileId);
            }

            // Validate retrieved data
            $media = $this->validator->validateMediaData($media);

            // Cache with security metadata
            $this->cache->setSecure($cacheKey, $media, [
                'user_id' => $userId,
                'accessed_at' => time()
            ]);

            // Log successful retrieval
            $this->monitor->recordSuccess($operationId, [
                'file_size' => strlen(json_encode($media)),
                'cache_status' => 'miss'
            ]);

            return $media;

        } catch (\Exception $e) {
            // Log failure with full context
            $this->monitor->recordFailure($operationId, $e, [
                'file_id' => $fileId,
                'user_id' => $userId,
                'error_type' => get_class($e)
            ]);

            // Always rethrow for proper error handling
            throw $e;
        }
    }

    /**
     * Generate secure cache key with user context
     */
    private function generateSecureCacheKey(string $fileId, string $userId): string
    {
        return hash_hmac(
            'sha256',
            "media:$fileId",
            $userId . $this->security->getSessionToken()
        );
    }
}
