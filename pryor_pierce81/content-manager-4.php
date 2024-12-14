<?php

namespace App\Core\CMS;

class CriticalContentManager
{
    private $security;
    private $storage;
    private $monitor;
    private $validator;

    public function createContent(array $data, string $userId): int
    {
        $operationId = $this->monitor->startOperation('content_create');

        try {
            // Validate input
            $this->validator->validateContentData($data);

            // Security checks
            $this->security->validateUserAccess($userId, 'content.create');

            // Sanitize content
            $sanitized = $this->security->sanitizeContent($data);

            // Store with transaction
            return DB::transaction(function() use ($sanitized, $userId, $operationId) {
                $id = $this->storage->create([
                    'data' => $sanitized,
                    'user_id' => $userId,
                    'created_at' => time()
                ]);

                $this->monitor->contentCreated($operationId, $id);
                return $id;
            });

        } catch (\Exception $e) {
            $this->monitor->operationFailed($operationId, $e);
            throw $e;
        }
    }

    public function getContent(int $id, string $userId): array
    {
        $operationId = $this->monitor->startOperation('content_read');

        try {
            // Security check
            $this->security->validateUserAccess($userId, 'content.read');

            // Get content with caching
            $content = $this->storage->findWithCache($id);
            if (!$content) {
                throw new ContentNotFoundException();
            }

            $this->monitor->contentRetrieved($operationId, $id);
            return $content;

        } catch (\Exception $e) {
            $this->monitor->operationFailed($operationId, $e);
            throw $e;
        }
    }
}
