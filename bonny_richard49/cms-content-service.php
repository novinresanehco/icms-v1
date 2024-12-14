<?php

namespace App\Core\CMS;

class ContentService extends BaseCmsService
{
    private ContentRepository $repository;
    private ContentValidator $contentValidator;
    private array $config;

    protected function getValidationRules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'status' => 'required|in:draft,published,archived',
            'author_id' => 'required|integer|exists:users,id',
            'category_id' => 'required|integer|exists:categories,id',
            'tags' => 'array',
            'meta' => 'array',
            'publish_at' => 'date|after:now',
            'expire_at' => 'date|after:publish_at'
        ];
    }

    protected function generateCacheKey(string $operation, array $context): string
    {
        return sprintf(
            'content:%s:%s',
            $operation,
            md5(json_encode($context))
        );
    }

    protected function executeCreate(array $data, array $context)
    {
        // Additional content-specific validation
        $this->contentValidator->validateForCreation($data);

        // Process content
        $processedData = $this->processContentData($data);

        // Create content
        $content = $this->repository->create($processedData);

        // Process tags if present
        if (isset($data['tags'])) {
            $this->processTags($content, $data['tags']);
        }

        // Handle meta data
        if (isset($data['meta'])) {
            $this->processMetaData($content, $data['meta']);
        }

        return $content;
    }

    protected function executeUpdate(array $data, array $context)
    {
        // Validate content exists
        $content = $this->repository->findOrFail($context['id']);

        // Additional content-specific validation
        $this->contentValidator->validateForUpdate($data, $content);

        // Process content updates
        $processedData = $this->processContentData($data);

        // Update content
        $updatedContent = $this->repository->update($content->id, $processedData);

        // Update tags if present
        if (isset($data['tags'])) {
            $this->processTags($updatedContent, $data['tags']);
        }

        // Update meta data if present
        if (isset($data['meta'])) {
            $this->processMetaData($updatedContent, $data['meta']);
        }

        return $updatedContent;
    }

    protected function executeDelete(array $data, array $context)
    {
        // Validate content exists
        $content = $this->repository->findOrFail($context['id']);

        // Check for dependencies
        $this->checkDependencies($content);

        // Archive content instead of hard delete if configured
        if ($this->config['soft_delete']) {
            return $this->archiveContent($content);
        }

        // Perform hard delete
        return $this->repository->delete($content->id);
    }

    protected function processContentData(array $data): array
    {
        // Sanitize content
        $data['content'] = $this->sanitizeContent($data['content']);

        // Generate SEO-friendly slug
        if (isset($data['title'])) {
            $data['slug'] = $this->generateSlug($data['title']);
        }

        // Add version info
        $data['version'] = $this->getNextVersion($data);

        return $data;
    }

    protected function sanitizeContent(string $content): string
    {
        return $this->contentValidator->sanitize($content);
    }

    protected function generateSlug(string $title): string
    {
        $slug = str_slug($title);
        
        // Ensure unique slug
        $count = 1;
        $originalSlug = $slug;
        
        while ($this->repository->slugExists($slug)) {
            $slug = "{$originalSlug}-{$count}";
            $count++;
        }

        return $slug;
    }

    protected function processTags(Content $content, array $tags): void
    {
        $this->repository->syncTags($content->id, $tags);
    }

    protected function processMetaData(Content $content, array $meta): void
    {
        $this->repository->updateMeta($content->id, $meta);
    }

    protected function checkDependencies(Content $content): void
    {
        if ($this->repository->hasDependencies($content->id)) {
            throw new ContentDependencyException(
                'Content has active dependencies and cannot be deleted'
            );
        }
    }

    protected function archiveContent(Content $content): bool
    {
        return $this->repository->update($content->id, [
            'status' => 'archived',
            'archived_at' => now()
        ]);
    }

    protected function getNextVersion(array $data): int
    {
        if (!isset($data['id'])) {
            return 1;
        }

        $currentVersion = $this->repository->getCurrentVersion($data['id']);
        return $currentVersion + 1;
    }

    protected function notifyAdministrators(
        \Throwable $e,
        string $operation,
        array $context
    ): void {
        // Send notifications through configured channels
        foreach ($this->config['admin_notification_channels'] as $channel) {
            $channel->notify([
                'type' => 'content_operation_failure',
                'operation' => $operation,
                'context' => $context,
                'error' => $e->getMessage(),
                'severity' => 'CRITICAL'
            ]);
        }
    }
}
