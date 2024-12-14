<?php

namespace App\Core\CMS;

use App\Core\Security\CoreSecurityManager;
use App\Core\Services\ValidationService;
use App\Core\Interfaces\{
    ContentRepositoryInterface,
    CacheManagerInterface
};
use App\Core\Exceptions\{
    ContentException,
    SecurityException,
    ValidationException
};
use Illuminate\Support\Facades\DB;

class ContentManager
{
    private ContentRepositoryInterface $repository;
    private CoreSecurityManager $security;
    private ValidationService $validator;
    private CacheManagerInterface $cache;

    public function __construct(
        ContentRepositoryInterface $repository,
        CoreSecurityManager $security,
        ValidationService $validator,
        CacheManagerInterface $cache
    ) {
        $this->repository = $repository;
        $this->security = $security;
        $this->validator = $validator;
        $this->cache = $cache;
    }

    public function createContent(array $data, array $context): ContentModel
    {
        return $this->security->executeSecureOperation(
            function() use ($data, $context) {
                // Validate content data
                $this->validator->validateInput($data);
                
                // Create content with versioning
                $content = $this->repository->create([
                    'data' => $this->security->encryptData(json_encode($data)),
                    'version' => 1,
                    'status' => ContentStatus::DRAFT,
                    'created_by' => $context['user_id'],
                    'created_at' => now(),
                    'metadata' => $this->generateMetadata($data)
                ]);

                // Clear relevant caches
                $this->cache->invalidatePattern("content:*");

                return $content;
            },
            $context
        );
    }

    public function updateContent(int $id, array $data, array $context): ContentModel
    {
        return $this->security->executeSecureOperation(
            function() use ($id, $data, $context) {
                // Get current content
                $content = $this->repository->findOrFail($id);
                
                // Version control
                $newVersion = $content->version + 1;
                
                // Create version record
                $this->repository->createVersion([
                    'content_id' => $id,
                    'version' => $content->version,
                    'data' => $content->data,
                    'metadata' => $content->metadata
                ]);

                // Update content
                $updated = $this->repository->update($id, [
                    'data' => $this->security->encryptData(json_encode($data)),
                    'version' => $newVersion,
                    'updated_by' => $context['user_id'],
                    'updated_at' => now(),
                    'metadata' => $this->generateMetadata($data)
                ]);

                // Clear caches
                $this->cache->invalidatePattern("content:$id:*");
                
                return $updated;
            },
            $context
        );
    }

    public function publishContent(int $id, array $context): ContentModel
    {
        return $this->security->executeSecureOperation(
            function() use ($id, $context) {
                // Get content
                $content = $this->repository->findOrFail($id);
                
                // Validate for publishing
                $this->validateForPublishing($content);
                
                // Update status
                $published = $this->repository->update($id, [
                    'status' => ContentStatus::PUBLISHED,
                    'published_at' => now(),
                    'published_by' => $context['user_id']
                ]);

                // Clear caches
                $this->cache->invalidatePattern("content:$id:*");
                $this->cache->invalidatePattern("published:*");
                
                return $published;
            },
            $context
        );
    }

    public function getContent(int $id, array $context): ContentModel
    {
        return $this->security->executeSecureOperation(
            function() use ($id) {
                return $this->cache->remember(
                    "content:$id",
                    3600,
                    function() use ($id) {
                        $content = $this->repository->findOrFail($id);
                        
                        // Decrypt content data
                        $content->data = json_decode(
                            $this->security->decryptData($content->data),
                            true
                        );
                        
                        return $content;
                    }
                );
            },
            $context
        );
    }

    public function listContent(array $filters, array $context): array
    {
        return $this->security->executeSecureOperation(
            function() use ($filters) {
                return $this->cache->remember(
                    "content:list:" . md5(json_encode($filters)),
                    1800,
                    function() use ($filters) {
                        return $this->repository->list($filters);
                    }
                );
            },
            $context
        );
    }

    public function deleteContent(int $id, array $context): bool
    {
        return $this->security->executeSecureOperation(
            function() use ($id) {
                // Soft delete
                $deleted = $this->repository->update($id, [
                    'deleted_at' => now(),
                    'deleted_by' => $context['user_id']
                ]);

                // Clear caches
                $this->cache->invalidatePattern("content:$id:*");
                $this->cache->invalidatePattern("content:list:*");

                return $deleted;
            },
            $context
        );
    }

    protected function generateMetadata(array $data): array
    {
        return [
            'title' => $data['title'] ?? '',
            'summary' => substr($data['content'] ?? '', 0, 200),
            'word_count' => str_word_count($data['content'] ?? ''),
            'language' => $data['language'] ?? 'en',
            'generated_at' => now()->toIso8601String()
        ];
    }

    protected function validateForPublishing(ContentModel $content): void
    {
        $requiredFields = ['title', 'content', 'author'];
        $contentData = json_decode($this->security->decryptData($content->data), true);

        foreach ($requiredFields as $field) {
            if (empty($contentData[$field])) {
                throw new ValidationException("Missing required field for publishing: $field");
            }
        }

        if ($content->status === ContentStatus::PUBLISHED) {
            throw new ContentException("Content is already published");
        }
    }
}

class ContentStatus
{
    public const DRAFT = 'draft';
    public const PUBLISHED = 'published';
    public const ARCHIVED = 'archived';
}

interface ContentModel
{
    public function getId(): int;
    public function getVersion(): int;
    public function getStatus(): string;
    public function getData(): array;
    public function getMetadata(): array;
}
