<?php

namespace App\Core\Content;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Exception\ContentException;
use App\Core\Validation\ValidationManagerInterface;
use Psr\Log\LoggerInterface;

class ContentManager implements ContentManagerInterface
{
    private SecurityManagerInterface $security;
    private ValidationManagerInterface $validator;
    private LoggerInterface $logger;
    private array $config;

    public function __construct(
        SecurityManagerInterface $security,
        ValidationManagerInterface $validator,
        LoggerInterface $logger,
        array $config = []
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    public function createContent(array $data, array $context): Content
    {
        $contentId = $this->generateContentId();

        try {
            DB::beginTransaction();

            $this->security->validateSecureOperation('content:create', $context);
            $this->validateContentData($data);
            
            $content = $this->processContentCreation($data, $context);
            $this->validateContentState($content);
            
            $this->audit->logContentCreation($contentId, $content);
            
            DB::commit();
            return $content;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleContentFailure($contentId, 'create', $e);
            throw new ContentException('Content creation failed', 0, $e);
        }
    }

    public function updateContent(int $id, array $data, array $context): Content
    {
        try {
            DB::beginTransaction();

            $this->security->validateSecureOperation('content:update', [
                'content_id' => $id
            ]);

            $content = $this->findContent($id);
            $this->validateContentUpdate($content, $data);

            $updatedContent = $this->processContentUpdate($content, $data);
            $this->validateContentState($updatedContent);

            $this->audit->logContentUpdate($id, $data);

            DB::commit();
            return $updatedContent;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleContentFailure($id, 'update', $e);
            throw new ContentException('Content update failed', 0, $e);
        }
    }

    public function publishContent(int $id, array $context): void
    {
        try {
            DB::beginTransaction();

            $this->security->validateSecureOperation('content:publish', [
                'content_id' => $id
            ]);

            $content = $this->findContent($id);
            $this->validateContentPublish($content);

            $this->processContentPublish($content);
            $this->audit->logContentPublish($id);

            $this->updateContentIndex($content);
            $this->invalidateContentCache($content);

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleContentFailure($id, 'publish', $e);
            throw new ContentException('Content publication failed', 0, $e);
        }
    }

    private function validateContentData(array $data): void
    {
        $this->validator->validateData($data, $this->config['content_rules']);

        if (isset($data['slug']) && $this->isSlugTaken($data['slug'])) {
            throw new ContentException('Content slug already exists');
        }

        foreach ($this->config['required_fields'] as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                throw new ContentException("Missing required field: {$field}");
            }
        }
    }

    private function processContentCreation(array $data, array $context): Content
    {
        $content = new Content();
        $content->fill($this->sanitizeContentData($data));
        
        $content->version = 1;
        $content->status = ContentStatus::DRAFT;
        $content->created_by = $context['user_id'];
        
        $this->setContentMeta($content);
        $this->processContentAttachments($content, $data);
        
        return $content;
    }

    private function validateContentState(Content $content): void
    {
        if (!$this->validator->validateContent($content)) {
            throw new ContentException('Content validation failed');
        }

        if (!$this->validateContentSecurity($content)) {
            throw new ContentException('Content security validation failed');
        }
    }

    private function updateContentIndex(Content $content): void
    {
        try {
            $this->searchIndex->updateDocument($content);
        } catch (\Exception $e) {
            $this->logger->error('Failed to update content index', [
                'content_id' => $content->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    private function handleContentFailure(string $id, string $operation, \Exception $e): void
    {
        $this->logger->error('Content operation failed', [
            'content_id' => $id,
            'operation' => $operation,
            'error' => $e->getMessage()
        ]);

        $this->audit->logContentFailure($id, $operation, $e);
    }

    private function getDefaultConfig(): array
    {
        return [
            'content_rules' => [
                'title' => ['required', 'max:255'],
                'body' => ['required'],
                'slug' => ['required', 'unique:contents'],
                'status' => ['required', 'in:draft,published']
            ],
            'required_fields' => ['title', 'body'],
            'max_attachments' => 10,
            'allowed_mime_types' => ['image/jpeg', 'image/png', 'application/pdf'],
            'max_file_size' => 10485760
        ];
    }
}
