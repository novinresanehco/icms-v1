<?php

namespace App\Core\CMS;

use App\Core\Security\SecurityManager;
use App\Core\Cache\CacheManager;
use App\Core\Storage\StorageManager;
use App\Core\Validation\ValidationService;
use App\Core\Audit\AuditLogger;

class ContentManager implements ContentManagerInterface
{
    private SecurityManager $security;
    private CacheManager $cache;
    private StorageManager $storage;
    private ValidationService $validator;
    private AuditLogger $audit;

    private const MAX_RETRIES = 3;
    private const CACHE_TTL = 3600;
    private const BATCH_SIZE = 100;

    public function __construct(
        SecurityManager $security,
        CacheManager $cache,
        StorageManager $storage,
        ValidationService $validator,
        AuditLogger $audit
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->storage = $storage;
        $this->validator = $validator;
        $this->audit = $audit;
    }

    public function createContent(ContentRequest $request): ContentResult
    {
        DB::beginTransaction();

        try {
            // Validate request
            $this->validateContentRequest($request);
            
            // Check security
            $this->security->validateAccess($request->getContext());
            
            // Process content
            $content = $this->processContent($request);
            
            // Store content
            $storedContent = $this->storeContent($content);
            
            // Update cache
            $this->updateCache($storedContent);
            
            DB::commit();
            
            // Log success
            $this->audit->logContentCreation($storedContent);
            
            return new ContentResult($storedContent);
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleContentFailure($e, $request);
            throw $e;
        }
    }

    public function updateContent(string $id, ContentRequest $request): ContentResult
    {
        DB::beginTransaction();

        try {
            // Load existing content
            $existing = $this->loadContent($id);
            
            // Validate update request
            $this->validateUpdateRequest($request, $existing);
            
            // Check security
            $this->security->validateContentAccess($request->getContext(), $existing);
            
            // Process update
            $updated = $this->processUpdate($existing, $request);
            
            // Store update
            $storedContent = $this->storeContentUpdate($updated);
            
            // Update cache
            $this->invalidateCache($id);
            $this->updateCache($storedContent);
            
            DB::commit();
            
            // Log update
            $this->audit->logContentUpdate($storedContent);
            
            return new ContentResult($storedContent);
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleUpdateFailure($e, $id, $request);
            throw $e;
        }
    }

    public function deleteContent(string $id, SecurityContext $context): void
    {
        DB::beginTransaction();

        try {
            // Load content
            $content = $this->loadContent($id);
            
            // Check security
            $this->security->validateContentDeletion($context, $content);
            
            // Process deletion
            $this->processContentDeletion($content);
            
            // Remove from cache
            $this->invalidateCache($id);
            
            DB::commit();
            
            // Log deletion
            $this->audit->logContentDeletion($content);
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleDeletionFailure($e, $id);
            throw $e;
        }
    }

    private function validateContentRequest(ContentRequest $request): void
    {
        if (!$this->validator->validateContent($request)) {
            throw new ContentValidationException('Invalid content request');
        }
    }

    private function processContent(ContentRequest $request): Content
    {
        $content = new Content(
            $request->getData(),
            $request->getMetadata(),
            $request->getContext()
        );

        if (!$content->isValid()) {
            throw new ContentProcessingException('Content processing failed');
        }

        return $content;
    }

    private function storeContent(Content $content): StoredContent
    {
        $attempts = 0;
        
        while ($attempts < self::MAX_RETRIES) {
            try {
                return $this->storage->store(
                    $content->getData(),
                    $content->getMetadata()
                );
            } catch (StorageException $e) {
                $attempts++;
                if ($attempts >= self::MAX_RETRIES) {
                    throw $e;
                }
                usleep(100000 * $attempts); // Exponential backoff
            }
        }
    }

    private function updateCache(StoredContent $content): void
    {
        $this->cache->set(
            $this->getCacheKey($content->getId()),
            $content,
            self::CACHE_TTL
        );
    }

    private function loadContent(string $id): StoredContent
    {
        // Try cache first
        $cached = $this->cache->get($this->getCacheKey($id));
        if ($cached) {
            return $cached;
        }

        // Load from storage
        $content = $this->storage->load($id);
        if (!$content) {
            throw new ContentNotFoundException("Content not found: {$id}");
        }

        // Update cache
        $this->updateCache($content);

        return $content;
    }

    private function invalidateCache(string $id): void
    {
        $this->cache->delete($this->getCacheKey($id));
    }

    private function handleContentFailure(\Exception $e, ContentRequest $request): void
    {
        $this->audit->logContentFailure($e, [
            'request_id' => $request->getId(),
            'user_id' => $request->getContext()->getUserId(),
            'timestamp' => now()
        ]);
    }

    private function getCacheKey(string $id): string
    {
        return "content:{$id}";
    }

    private function validateUpdateRequest(ContentRequest $request, StoredContent $existing): void
    {
        if (!$this->validator->validateUpdate($request, $existing)) {
            throw new ContentValidationException('Invalid update request');
        }
    }

    private function processUpdate(StoredContent $existing, ContentRequest $request): Content
    {
        return new Content(
            array_merge($existing->getData(), $request->getData()),
            array_merge($existing->getMetadata(), $request->getMetadata()),
            $request->getContext()
        );
    }

    private function processContentDeletion(StoredContent $content): void
    {
        // Archive content first
        $this->archiveContent($content);
        
        // Delete from storage
        $this->storage->delete($content->getId());
    }

    private function archiveContent(StoredContent $content): void
    {
        $this->storage->archive(
            $content->getId(),
            $content->getData(),
            $content->getMetadata()
        );
    }
}
