<?php

namespace App\Core\CMS;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Cache\CacheManagerInterface;
use App\Core\Validation\ValidationManagerInterface;
use App\Core\Exception\CMSException;
use Psr\Log\LoggerInterface;
use Illuminate\Support\Facades\DB;

class ContentManager implements ContentManagerInterface
{
    private SecurityManagerInterface $security;
    private CacheManagerInterface $cache;
    private ValidationManagerInterface $validator;
    private LoggerInterface $logger;
    private array $config;

    public function __construct(
        SecurityManagerInterface $security,
        CacheManagerInterface $cache,
        ValidationManagerInterface $validator,
        LoggerInterface $logger,
        array $config = []
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->validator = $validator;
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    public function createContent(array $data, array $options = []): Content
    {
        $operationId = $this->generateOperationId();

        try {
            DB::beginTransaction();

            $this->security->validateSecureOperation('content:create', [
                'operation_id' => $operationId,
                'user_id' => $options['user_id'] ?? null
            ]);

            $this->validateContentData($data);
            $this->validateContentOptions($options);

            $content = $this->executeContentCreation($data, $options);
            
            $this->verifyContentCreation($content);
            $this->updateContentCache($content);
            
            $this->logContentOperation($operationId, 'create', $content->getId());

            DB::commit();
            
            return $content;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleContentFailure($operationId, 'create', null, $e);
            throw new CMSException('Content creation failed', 0, $e);
        }
    }

    public function updateContent(int $id, array $data, array $options = []): Content
    {
        $operationId = $this->generateOperationId();

        try {
            DB::beginTransaction();

            $this->security->validateSecureOperation('content:update', [
                'operation_id' => $operationId,
                'content_id' => $id,
                'user_id' => $options['user_id'] ?? null
            ]);

            $content = $this->findContent($id);
            $this->validateContentUpdate($content, $data, $options);

            $updatedContent = $this->executeContentUpdate($content, $data, $options);
            
            $this->verifyContentUpdate($updatedContent);
            $this->updateContentCache($updatedContent);
            
            $this->logContentOperation($operationId, 'update', $id);

            DB::commit();
            
            return $updatedContent;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleContentFailure($operationId, 'update', $id, $e);
            throw new CMSException('Content update failed', 0, $e);
        }
    }

    public function deleteContent(int $id, array $options = []): bool
    {
        $operationId = $this->generateOperationId();

        try {
            DB::beginTransaction();

            $this->security->validateSecureOperation('content:delete', [
                'operation_id' => $operationId,
                'content_id' => $id,
                'user_id' => $options['user_id'] ?? null
            ]);

            $content = $this->findContent($id);
            $this->validateContentDeletion($content, $options);

            $success = $this->executeContentDeletion($content, $options);
            
            $this->verifyContentDeletion($id);
            $this->removeContentCache($id);
            
            $this->logContentOperation($operationId, 'delete', $id);

            DB::commit();
            
            return $success;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleContentFailure($operationId, 'delete', $id, $e);
            throw new CMSException('Content deletion failed', 0, $e);
        }
    }

    public function getContent(int $id, array $options = []): Content
    {
        $operationId = $this->generateOperationId();

        try {
            $this->security->validateSecureOperation('content:read', [
                'operation_id' => $operationId,
                'content_id' => $id,
                'user_id' => $options['user_id'] ?? null
            ]);

            $cacheKey = $this->generateContentCacheKey($id, $options);
            
            $content = $this->cache->get($cacheKey);
            
            if (!$content) {
                $content = $this->findContent($id);
                $this->validateContentAccess($content, $options);
                $this->cache->store($cacheKey, $content);
            }

            $this->logContentOperation($operationId, 'read', $id);
            
            return $content;

        } catch (\Exception $e) {
            $this->handleContentFailure($operationId, 'read', $id, $e);
            throw new CMSException('Content retrieval failed', 0, $e);
        }
    }

    protected function validateContentData(array $data): void
    {
        if (!$this->validator->validate($data, $this->config['content_rules'])) {
            throw new CMSException('Invalid content data');
        }
    }

    protected function validateContentOptions(array $options): void
    {
        if (!$this->validator->validate($options, $this->config['option_rules'])) {
            throw new CMSException('Invalid content options');
        }
    }

    protected function validateContentAccess(Content $content, array $options): void
    {
        if (!$this->security->checkContentAccess($content, $options)) {
            throw new CMSException('Content access denied');
        }
    }

    protected function executeContentCreation(array $data, array $options): Content
    {
        $processedData = $this->processContentData($data);
        return Content::create($processedData);
    }

    protected function executeContentUpdate(Content $content, array $data, array $options): Content
    {
        $processedData = $this->processContentData($data);
        $content->update($processedData);
        return $content;
    }

    protected function executeContentDeletion(Content $content, array $options): bool
    {
        return $content->delete();
    }

    protected function verifyContentCreation(Content $content): void
    {
        if (!$content->exists || !$content->isValid()) {
            throw new CMSException('Content creation verification failed');
        }
    }

    protected function verifyContentUpdate(Content $content): void
    {
        if (!$content->isValid()) {
            throw new CMSException('Content update verification failed');
        }
    }

    protected function verifyContentDeletion(int $id): void
    {
        if ($this->contentExists($id)) {
            throw new CMSException('Content deletion verification failed');
        }
    }

    protected function updateContentCache(Content $content): void
    {
        $cacheKey = $this->generateContentCacheKey($content->getId());
        $this->cache->store($cacheKey, $content);
    }

    protected function removeContentCache(int $id): void
    {
        $cacheKey = $this->generateContentCacheKey($id);
        $this->cache->remove($cacheKey);
    }

    protected function processContentData(array $data): array
    {
        // Add security metadata
        return array_merge($data, [
            'processed_at' => time(),
            'security_hash' => $this->generateSecurityHash($data),
            'version' => $this->generateVersionId()
        ]);
    }

    protected function generateOperationId(): string
    {
        return uniqid('content_', true);
    }

    protected function generateContentCacheKey(int $id, array $options = []): string
    {
        return sprintf('content:%d:%s', $id, md5(serialize($options)));
    }

    protected function generateSecurityHash(array $data): string
    {
        return hash_hmac('sha256', serialize($data), $this->config['security_key']);
    }

    protected function generateVersionId(): string
    {
        return uniqid('v', true);
    }

    protected function getDefaultConfig(): array
    {
        return [
            'content_rules' => [
                'title' => 'required|string|max:255',
                'content' => 'required|string',
                'status' => 'required|in:draft,published,archived'
            ],
            'option_rules' => [
                'user_id' => 'required|integer',
                'locale' => 'string|size:2',
                'version' => 'string'
            ],
            'security_key' => env('CMS_SECURITY_KEY'),
            'cache_ttl' => 3600,
            'max_versions' => 10
        ];
    }

    protected function handleContentFailure(string $operationId, string $operation, ?int $contentId, \Exception $e): void
    {
        $this->logger->error('Content operation failed', [
            'operation_id' => $operationId,
            'operation' => $operation,
            'content_id' => $contentId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        $this->notifyContentFailure($operationId, $operation, $contentId, $e);
    }
}
