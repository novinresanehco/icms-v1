<?php

namespace App\Core\CMS;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Validation\ValidationService;
use App\Core\Exception\CMSException;
use Psr\Log\LoggerInterface;

class ContentManagementService implements ContentManagementInterface
{
    private SecurityManagerInterface $security;
    private ValidationService $validator;
    private LoggerInterface $logger;
    private array $config;

    public function __construct(
        SecurityManagerInterface $security,
        ValidationService $validator,
        LoggerInterface $logger,
        array $config = []
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    public function createContent(array $data): ContentResult
    {
        $operationId = $this->generateOperationId();

        try {
            DB::beginTransaction();

            $this->security->validateContext('content:create');
            $this->validateContentData($data);

            $content = $this->executeContentCreation($data);
            $this->validateCreatedContent($content);

            $this->logContentCreation($operationId, $content);

            DB::commit();
            return new ContentResult(true, $content);

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleOperationFailure($operationId, 'create', $data, $e);
            throw $e;
        }
    }

    public function updateContent(int $id, array $data): ContentResult
    {
        $operationId = $this->generateOperationId();

        try {
            DB::beginTransaction();

            $this->security->validateContext('content:update');
            $this->validateContentData($data);
            $this->validateContentAccess($id);

            $content = $this->executeContentUpdate($id, $data);
            $this->validateUpdatedContent($content);

            $this->logContentUpdate($operationId, $content);

            DB::commit();
            return new ContentResult(true, $content);

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleOperationFailure($operationId, 'update', $data, $e);
            throw $e;
        }
    }

    public function deleteContent(int $id): bool
    {
        $operationId = $this->generateOperationId();

        try {
            DB::beginTransaction();

            $this->security->validateContext('content:delete');
            $this->validateContentAccess($id);

            $result = $this->executeContentDeletion($id);
            $this->validateDeletionResult($result);

            $this->logContentDeletion($operationId, $id);

            DB::commit();
            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleOperationFailure($operationId, 'delete', ['id' => $id], $e);
            throw $e;
        }
    }

    private function validateContentData(array $data): void
    {
        $violations = $this->validator->validate($data, [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'status' => 'required|in:draft,published,archived',
            'author_id' => 'required|numeric',
            'meta_data' => 'array'
        ]);

        if (!empty($violations)) {
            throw new CMSException('Invalid content data');
        }
    }

    private function validateContentAccess(int $id): void
    {
        if (!$this->security->hasPermission("content:{$id}")) {
            throw new CMSException('Access denied to content');
        }
    }

    private function executeContentCreation(array $data): Content
    {
        $content = new Content($data);
        $content->setCreatedAt(now());
        $content->setAuthor($this->security->getCurrentUser());
        
        DB::table('contents')->insert($content->toArray());
        
        return $content;
    }

    private function executeContentUpdate(int $id, array $data): Content
    {
        $content = DB::table('contents')->find($id);
        
        if (!$content) {
            throw new CMSException('Content not found');
        }
        
        $content->fill($data);
        $content->setUpdatedAt(now());
        
        DB::table('contents')->where('id', $id)->update($content->toArray());
        
        return $content;
    }

    private function executeContentDeletion(int $id): bool
    {
        return DB::table('contents')->where('id', $id)->delete() > 0;
    }

    private function validateCreatedContent(Content $content): void
    {
        if (!$content->isValid()) {
            throw new CMSException('Content validation failed after creation');
        }
    }

    private function validateUpdatedContent(Content $content): void
    {
        if (!$content->isValid()) {
            throw new CMSException('Content validation failed after update');
        }
    }

    private function validateDeletionResult(bool $result): void
    {
        if (!$result) {
            throw new CMSException('Content deletion failed');
        }
    }

    private function generateOperationId(): string
    {
        return uniqid('cms_', true);
    }

    private function logContentCreation(string $operationId, Content $content): void
    {
        $this->logger->info('Content created successfully', [
            'operation_id' => $operationId,
            'content_id' => $content->getId(),
            'author_id' => $content->getAuthorId(),
            'timestamp' => microtime(true)
        ]);
    }

    private function logContentUpdate(string $operationId, Content $content): void
    {
        $this->logger->info('Content updated successfully', [
            'operation_id' => $operationId,
            'content_id' => $content->getId(),
            'modifier_id' => $this->security->getCurrentUser()->getId(),
            'timestamp' => microtime(true)
        ]);
    }

    private function logContentDeletion(string $operationId, int $id): void
    {
        $this->logger->info('Content deleted successfully', [
            'operation_id' => $operationId,
            'content_id' => $id,
            'deleter_id' => $this->security->getCurrentUser()->getId(),
            'timestamp' => microtime(true)
        ]);
    }

    private function handleOperationFailure(
        string $operationId,
        string $operation,
        array $data,
        \Exception $e
    ): void {
        $this->logger->error('Content operation failed', [
            'operation_id' => $operationId,
            'operation' => $operation,
            'data' => $data,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    private function getDefaultConfig(): array
    {
        return [
            'max_content_size' => 1048576, // 1MB
            'allowed_statuses' => ['draft', 'published', 'archived'],
            'version_control' => true,
            'auto_save' => true,
            'cache_enabled' => true
        ];
    }
}
