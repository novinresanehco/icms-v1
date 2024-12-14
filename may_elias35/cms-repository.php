<?php

namespace App\Core\CMS;

class ContentRepository implements ContentRepositoryInterface
{
    private DB $database;
    private CacheManager $cache;
    private ValidationService $validator;
    private SecurityManager $security;
    private AuditLogger $logger;
    private MetricsCollector $metrics;

    public function __construct(
        DB $database,
        CacheManager $cache,
        ValidationService $validator,
        SecurityManager $security,
        AuditLogger $logger,
        MetricsCollector $metrics
    ) {
        $this->database = $database;
        $this->cache = $cache;
        $this->validator = $validator;
        $this->security = $security;
        $this->logger = $logger;
        $this->metrics = $metrics;
    }

    public function create(array $data): ContentEntity
    {
        $startTime = microtime(true);
        DB::beginTransaction();

        try {
            $this->validator->validateContentData($data);
            $this->security->enforceCreatePermissions();

            $sanitizedData = $this->security->sanitizeInput($data);
            $versionData = $this->prepareVersionData($sanitizedData);
            
            $content = $this->database->table('content')->create($sanitizedData);
            $version = $this->database->table('content_versions')->create($versionData);

            $this->cache->invalidateContentCache();
            $this->logger->logContentCreation($content->id);
            
            DB::commit();

            $this->metrics->recordOperation('content_create', microtime(true) - $startTime);
            return new ContentEntity($content, $version);

        } catch (\Exception $e) {
            DB::rollBack();
            $this->logger->logError('content_create_failed', $e);
            throw new ContentOperationException('Content creation failed', 0, $e);
        }
    }

    public function update(int $id, array $data): ContentEntity
    {
        $startTime = microtime(true);
        DB::beginTransaction();

        try {
            $this->validator->validateContentData($data);
            $this->security->enforceUpdatePermissions($id);

            $existing = $this->database->table('content')->findOrFail($id);
            $sanitizedData = $this->security->sanitizeInput($data);
            $versionData = $this->prepareVersionData($sanitizedData, $existing);

            $content = $this->database->table('content')->update($id, $sanitizedData);
            $version = $this->database->table('content_versions')->create($versionData);

            $this->cache->invalidateContentCache($id);
            $this->logger->logContentUpdate($id);

            DB::commit();

            $this->metrics->recordOperation('content_update', microtime(true) - $startTime);
            return new ContentEntity($content, $version);

        } catch (\Exception $e) {
            DB::rollBack();
            $this->logger->logError('content_update_failed', $e);
            throw new ContentOperationException('Content update failed', 0, $e);
        }
    }

    public function delete(int $id): bool
    {
        DB::beginTransaction();

        try {
            $this->security->enforceDeletePermissions($id);
            
            $content = $this->database->table('content')->findOrFail($id);
            $this->archiveContent($content);
            
            $this->database->table('content')->delete($id);
            $this->cache->invalidateContentCache($id);
            $this->logger->logContentDeletion($id);

            DB::commit();
            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->logger->logError('content_delete_failed', $e);
            throw new ContentOperationException('Content deletion failed', 0, $e);
        }
    }

    public function find(int $id): ?ContentEntity
    {
        return $this->cache->remember(
            "content:{$id}",
            config('cache.ttl'),
            function() use ($id) {
                $content = $this->database->table('content')->find($id);
                if (!$content) return null;

                $version = $this->database->table('content_versions')
                    ->where('content_id', $id)
                    ->latest()
                    ->first();

                $this->logger->logContentAccess($id);
                return new ContentEntity($content, $version);
            }
        );
    }

    public function search(array $criteria): Collection
    {
        $cacheKey = "content:search:" . md5(serialize($criteria));

        return $this->cache->remember(
            $cacheKey,
            config('cache.ttl'),
            function() use ($criteria) {
                $query = $this->database->table('content');
                
                foreach ($criteria as $field => $value) {
                    $query->where($field, $value);
                }

                return $query->get()->map(function($content) {
                    return new ContentEntity($content, null);
                });
            }
        );
    }

    private function prepareVersionData(array $data, ?object $existing = null): array
    {
        return [
            'content_id' => $existing ? $existing->id : null,
            'data' => json_encode($data),
            'created_by' => $this->security->getCurrentUserId(),
            'created_at' => now(),
            'checksum' => $this->calculateChecksum($data)
        ];
    }

    private function archiveContent(object $content): void
    {
        $this->database->table('content_archive')->create([
            'content_id' => $content->id,
            'data' => json_encode($content),
            'archived_by' => $this->security->getCurrentUserId(),
            'archived_at' => now()
        ]);
    }

    private function calculateChecksum(array $data): string
    {
        return hash('sha256', json_encode($data));
    }
}
