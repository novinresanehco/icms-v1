<?php

namespace App\Core\Media;

/**
 * Critical media storage service interface
 */
interface MediaStorageInterface
{
    /**
     * Retrieve media with security validation
     *
     * @param string $fileId Media file identifier
     * @param string $userId Requesting user identifier
     * @throws AccessDeniedException If user lacks permission
     * @throws NotFoundException If media not found
     * @throws ValidationException If validation fails
     * @throws StorageException If storage operation fails
     * @return array Media data
     */
    public function retrieveMedia(string $fileId, string $userId): array;
}
