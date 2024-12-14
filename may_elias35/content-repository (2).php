<?php

namespace App\Core\CMS\Content;

use App\Core\CMS\Models\Content;
use App\Core\Security\SecurityManagerInterface;
use App\Core\Validation\ValidationService;
use App\Core\Cache\CacheManager;
use App\Core\Audit\AuditLogger;

class ContentRepository implements ContentRepositoryInterface
{
    private SecurityManagerInterface $security;
    private ValidationService $validator;
    private CacheManager $cache;
    private AuditLogger $audit;

    private const CACHE_PREFIX = 'content:';
    private const CACHE_TTL = 3600;

    public function __construct(
        SecurityManagerInterface $security,
        ValidationService $validator,
        CacheManager $cache,
        AuditLogger $audit
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->cache = $cache;
        $this->audit = $audit;
    }

    public function store(array $data): Content
    {
        DB::beginTransaction();

        try {
            // Validate input
            $validatedData = $this->validateContentData($data);

            // Apply security measures
            $secureData = $this->security->secureData($validatedData);

            // Store content
            $content = Content::create($secureData);

            // Store metadata
            $this->storeMetadata($content);

            // Create audit trail
            $this->audit->logContentCreation($content);

            DB::commit();

            // Update cache
            $this->updateCache($content);

            return $content;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleStoreFailure($e, $data);
            throw $e;
        }
    }

    public function update(int $id, array $data): Content
    {
        DB::beginTransaction();

        try {
            // Get existing content
            $content = $this->findOrFail($id);

            // Validate update data
            $validatedData = $this->validateUpdateData($data, $content);

            // Apply security measures
            $secureData = $this->security->secureData($validatedData);

            // Update content
            $content->update($secureData);

            // Update metadata
            $this->updateMetadata($content);

            // Create audit trail
            $this->audit->logContentUpdate($content);

            DB::commit();

            // Update cache
            $this->updateCache($content);

            return $content;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleUpdateFailure($e, $id, $data);
            throw $e;
        }
    }

    public function delete(int $id): bool
    {
        DB::beginTransaction();

        try {
            // Get content
            $content = $this->findOrFail($id);

            // Security check
            $this->security->validateDeletion($content);

            // Delete content
            $result = $content->delete();

            // Create audit trail
            $this->audit->logContentDeletion($content);

            DB::commit();

            // Clear cache
            $this->clearCache($content);

            return $result;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleDeleteFailure($e, $id);
            throw $e;
        }
    }

    public function find(int $id): ?Content
    {
        // Try cache first
        $cacheKey = $this->getCacheKey($id);
        
        if ($cached = $this->cache->get($cacheKey)) {
            return $cached;
        }

        // Get from database
        $content = Content::find($id);

        if ($content) {
            $this->cache->set($cacheKey, $content, self::CACHE_TTL);
        }

        return $content;
    }

    private function validateContentData(array $data): array
    {
        $rules = [
            'title' => 'required|max:200',
            'content' => 'required',
            'status' => 'required|in:draft,published',
            'author_id' => 'required|exists:users,id'
        ];

        return $this->validator->validate($data, $rules);
    }

    private function validateUpdateData(array $data, Content $content): array
    {
        $rules = [
            'title' => 'max:200',
            'status' => 'in:draft,published'
        ];

        return $this->validator->validate($data, $rules);
    }

    private function storeMetadata(Content $content): void
    {
        ContentMetadata::create([
            'content_id' => $content->id,
            'version' => 1,
            'created_at' => now(),
            'created_by' => auth()->id(),
            'checksum' => $this->generateChecksum($content)
        ]);
    }

    private function updateMetadata(Content $content): void
    {
        $content->metadata()->create([
            'version' => $content->metadata->version + 1,
            'updated_at' => now(),
            'updated_by' => auth()->id(),
            'checksum' => $this->generateChecksum($content)
        ]);
    }

    private function generateChecksum(Content $content): string
    {
        return hash('sha256', serialize($content->toArray()));
    }

    private function getCacheKey(int $id): string
    {
        return self::CACHE_PREFIX . $id;
    }

    private function updateCache(Content $content): void
    {
        $this->cache->set(
            $this->getCacheKey($content->id),
            $content,
            self::CACHE_TTL
        );
    }

    private function clearCache(Content $content): void
    {
        $this->cache->delete($this->getCacheKey($content->id));
    }

    private function handleStoreFailure(\Exception $e, array $data): void
    {
        $this->audit->logFailure('content_store_failed', [
            'data' => $data,
            'error' => $e->getMessage()
        ]);
    }

    private function handleUpdateFailure(\Exception $e, int $id, array $data): void
    {
        $this->audit->logFailure('content_update_failed', [
            'id' => $id,
            'data' => $data,
            'error' => $e->getMessage()
        ]);
    }

    private function handleDeleteFailure(\Exception $e, int $id): void
    {
        $this->audit->logFailure('content_delete_failed', [
            'id' => $id,
            'error' => $e->getMessage()
        ]);
    }
}
