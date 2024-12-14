<?php

namespace App\Modules\Content;

use App\Core\Service\BaseService;
use App\Core\Events\ContentEvent;
use App\Core\Support\Result;
use App\Core\Exceptions\ContentException;
use App\Models\Content;

class ContentService extends BaseService
{
    protected array $validationRules = [
        'create' => [
            'title' => 'required|string|max:255',
            'slug' => 'required|string|unique:contents,slug',
            'content' => 'required|string',
            'status' => 'required|in:draft,published',
            'meta_description' => 'nullable|string|max:255',
            'meta_keywords' => 'nullable|string|max:255',
            'template' => 'required|string|exists:templates,name',
            'category_id' => 'required|exists:categories,id',
            'tags' => 'nullable|array',
            'tags.*' => 'exists:tags,id',
            'media' => 'nullable|array',
            'media.*' => 'exists:media,id'
        ],
        'update' => [
            'title' => 'string|max:255',
            'slug' => 'string|unique:contents,slug',
            'content' => 'string',
            'status' => 'in:draft,published',
            'meta_description' => 'nullable|string|max:255',
            'meta_keywords' => 'nullable|string|max:255',
            'template' => 'string|exists:templates,name',
            'category_id' => 'exists:categories,id',
            'tags' => 'nullable|array',
            'tags.*' => 'exists:tags,id',
            'media' => 'nullable|array',
            'media.*' => 'exists:media,id'
        ]
    ];

    protected array $permissions = [
        'create' => 'content.create',
        'update' => 'content.update',
        'delete' => 'content.delete',
        'publish' => 'content.publish'
    ];

    protected array $cacheKeys = [
        'create' => ['contents', 'contents.list'],
        'update' => ['contents', 'contents.list', 'content.{id}'],
        'delete' => ['contents', 'contents.list', 'content.{id}'],
        'publish' => ['contents', 'contents.list', 'content.{id}']
    ];

    public function create(array $data): Result
    {
        return $this->executeOperation('create', $data);
    }

    public function update(int $id, array $data): Result
    {
        $data['id'] = $id;
        return $this->executeOperation('update', $data);
    }

    public function delete(int $id): Result
    {
        return $this->executeOperation('delete', ['id' => $id]);
    }

    public function publish(int $id): Result
    {
        return $this->executeOperation('publish', ['id' => $id]);
    }

    protected function processOperation(string $operation, array $data, array $context): mixed
    {
        return match($operation) {
            'create' => $this->processCreate($data),
            'update' => $this->processUpdate($data),
            'delete' => $this->processDelete($data),
            'publish' => $this->processPublish($data),
            default => throw new ContentException("Invalid operation: {$operation}")
        };
    }

    protected function processCreate(array $data): Content
    {
        // Create content
        $content = $this->repository->create($data);

        // Process relationships
        if (isset($data['tags'])) {
            $content->tags()->sync($data['tags']);
        }

        if (isset($data['media'])) {
            $content->media()->sync($data['media']);
        }

        // Process related content
        $this->processRelatedContent($content, $data);

        // Generate search index
        $this->generateSearchIndex($content);

        // Fire events
        $this->events->dispatch(new ContentEvent('created', $content));

        return $content;
    }

    protected function processUpdate(array $data): Content
    {
        $content = $this->repository->findOrFail($data['id']);
        
        // Update content
        $updatedContent = $this->repository->update($content, $data);

        // Update relationships
        if (isset($data['tags'])) {
            $updatedContent->tags()->sync($data['tags']);
        }

        if (isset($data['media'])) {
            $updatedContent->media()->sync($data['media']);
        }

        // Update related content
        $this->processRelatedContent($updatedContent, $data);

        // Update search index
        $this->generateSearchIndex($updatedContent);

        // Fire events
        $this->events->dispatch(new ContentEvent('updated', $updatedContent));

        return $updatedContent;
    }

    protected function processDelete(array $data): bool
    {
        $content = $this->repository->findOrFail($data['id']);

        // Delete relationships
        $content->tags()->detach();
        $content->media()->detach();

        // Remove from search index
        $this->removeFromSearchIndex($content);

        // Delete content
        $deleted = $this->repository->delete($content);

        // Fire events
        $this->events->dispatch(new ContentEvent('deleted', $content));

        return $deleted;
    }

    protected function processPublish(array $data): Content
    {
        $content = $this->repository->findOrFail($data['id']);
        
        // Validate can publish
        if (!$content->canPublish()) {
            throw new ContentException('Content cannot be published');
        }

        // Update status
        $content = $this->repository->update($content, [
            'status' => 'published',
            'published_at' => now()
        ]);

        // Rebuild cache
        $this->rebuildContentCache($content);

        // Update search index
        $this->generateSearchIndex($content);

        // Fire events
        $this->events->dispatch(new ContentEvent('published', $content));

        return $content;
    }

    protected function getValidationRules(string $operation): array
    {
        return $this->validationRules[$operation] ?? [];
    }

    protected function getRequiredPermissions(string $operation): array
    {
        return [$this->permissions[$operation]];
    }

    protected function isValidResult(string $operation, $result): bool
    {
        return match($operation) {
            'create', 'update', 'publish' => $result instanceof Content,
            'delete' => is_bool($result),
            default => false
        };
    }

    protected function processRelatedContent(Content $content, array $data): void
    {
        if (isset($data['related_contents'])) {
            $content->relatedContents()->sync($data['related_contents']);
        }
    }

    protected function generateSearchIndex(Content $content): void
    {
        // Implementation of search index generation
    }

    protected function removeFromSearchIndex(Content $content): void
    {
        // Implementation of search index removal
    }

    protected function rebuildContentCache(Content $content): void
    {
        // Implementation of cache rebuilding
    }
}
