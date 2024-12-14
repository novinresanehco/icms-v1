<?php

namespace App\Core\Media;

class MediaStorageService
{
    private $store;
    private $cache;
    private $monitor;

    public function retrieveMedia(string $fileId, string $userId): array
    {
        $operationId = $this->monitor->startOperation('media_retrieve');

        try {
            // Check permissions
            if (!$this->hasAccess($userId, $fileId)) {
                throw new AccessDeniedException();
            }

            // Try cache
            if ($cached = $this->cache->get("media:$fileId")) {
                return $cached;
            }

            // Get from storage
            $media = $this->store->get($fileId);
            if (!$media) {
                throw new NotFoundException();
            }

            // Cache result
            $this->cache->set("media:$fileId", $media);

            $this->monitor->retrieveSuccess($operationId);
            return $media;

        } catch (\Exception $e) {
            $this->monitor->retrieveFailure($operationId, $e);
            throw $e;
        }
    }

    private function hasAccess(string $userId, string $fileId): bool
    {
        return $this->store->checkAccess($userId, $fileId);
    }
}
