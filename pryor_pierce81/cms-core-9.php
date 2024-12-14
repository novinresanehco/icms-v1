<?php

namespace App\Core\CMS;

use App\Core\Security\SecurityCoreInterface;
use App\Core\Exception\CMSException;
use App\Core\Validation\ValidationManagerInterface;
use Psr\Log\LoggerInterface;

class CMSCore implements CMSCoreInterface
{
    private SecurityCoreInterface $security;
    private ValidationManagerInterface $validator;
    private LoggerInterface $logger;
    private array $config;

    public function __construct(
        SecurityCoreInterface $security,
        ValidationManagerInterface $validator,
        LoggerInterface $logger,
        array $config = []
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    public function createContent(array $data, array $context): ContentEntity
    {
        $operationId = $this->generateOperationId();
        
        try {
            DB::beginTransaction();

            $this->security->validateSecureOperation('content:create', $context);
            $this->validateContentData($data);
            
            $content = $this->processContentCreation($data, $context);
            $this->validateContent($content);
            
            $this->logContentOperation($operationId, 'create', $content);
            
            DB::commit();
            return $content;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleContentFailure($operationId, 'create', $e);
            throw new CMSException('Content creation failed', 0, $e);
        }
    }

    public function updateContent(int $id, array $data, array $context): ContentEntity
    {
        $operationId = $this->generateOperationId();
        
        try {
            DB::beginTransaction();

            $this->security->validateSecureOperation('content:update', $context);
            $this->validateContentExists($id);
            $this->validateContentData($data);
            
            $content = $this->processContentUpdate($id, $data, $context);
            $this->validateContent($content);
            
            $this->logContentOperation($operationId, 'update', $content);
            
            DB::commit();
            return $content;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleContentFailure($operationId, 'update', $e);
            throw new CMSException('Content update failed', 0, $e);
        }
    }

    private function validateContentData(array $data): void
    {
        if (!$this->validator->validateContentSchema($data)) {
            throw new CMSException('Invalid content schema');
        }

        if (!$this->validator->validateContentRules($data)) {
            throw new CMSException('Content validation failed');
        }

        foreach ($this->config['content_constraints'] as $constraint) {
            if (!$this->validator->validateConstraint($constraint, $data)) {
                throw new CMSException("Content constraint violation: {$constraint}");
            }
        }
    }

    private function processContentCreation(array $data, array $context): ContentEntity
    {
        $data = $this->sanitizeContent($data);
        $data = $this->enrichContent($data, $context);
        
        $content = $this->contentRepository->create($data);
        
        $this->processContentHooks('create', $content, $context);
        $this->updateContentIndex($content);
        
        return $content;
    }

    private function processContentUpdate(int $id, array $data, array $context): ContentEntity
    {
        $data = $this->sanitizeContent($data);
        $data = $this->enrichContent($data, $context);
        
        $content = $this->contentRepository->update($id, $data);
        
        $this->processContentHooks('update', $content, $context);
        $this->updateContentIndex($content);
        
        return $content;
    }

    private function handleContentFailure(string $operationId, string $operation, \Exception $e): void
    {
        $this->logger->error('Content operation failed', [
            'operation_id' => $operationId,
            'operation' => $operation,
            'error' => $e->getMessage()
        ]);

        $this->executeFailureRecovery($operationId, $operation);
    }

    private function getDefaultConfig(): array
    {
        return [
            'content_constraints' => [
                'size_limit',
                'format_check',
                'security_scan',
                'version_control'
            ],
            'index_update' => true,
            'cache_enabled' => true,
            'hooks_enabled' => true
        ];
    }
}
