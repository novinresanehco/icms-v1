<?php

namespace App\Core\Services;

use App\Core\Repositories\ContentRepository;
use App\Core\Security\AuditService;
use App\Core\Events\{ContentCreated, ContentUpdated, ContentDeleted};
use App\Exceptions\ContentException;
use App\Models\Content;
use Illuminate\Support\Facades\{Cache, Event};

class ContentService
{
    protected ContentRepository $repository;
    protected ValidationService $validator;
    protected AuditService $auditService;
    protected array $searchableFields = ['title', 'description', 'content'];

    public function __construct(
        ContentRepository $repository,
        ValidationService $validator,
        AuditService $auditService
    ) {
        $this->repository = $repository;
        $this->validator = $validator;
        $this->auditService = $auditService;
    }

    public function create(array $data): Content
    {
        // Additional validation
        $this->validator->validateContent($data);

        // Create content
        $content = $this->repository->create($data);

        // Log creation
        $this->auditService->logSecurityEvent('content_created', [
            'content_id' => $content->id,
            'type' => $content->type
        ]);

        // Dispatch creation event
        Event::dispatch(new ContentCreated($content));

        // Clear relevant caches
        $this->clearContentCaches($content);

        return $content;
    }

    public function update(int $id, array $data): Content
    {
        $content = $this->findOrFail($id);

        // Validate update data
        $this->validator->validateContent($data, $content);

        // Update content
        $content = $this->repository->update($id, $data);

        // Log update
        $this->auditService->logSecurityEvent('content_updated', [
            'content_id' => $content->id,
            'type' => $content->type
        ]);

        // Dispatch update event
        Event::dispatch(new ContentUpdated($content));

        // Clear caches
        $this->clearContentCaches($content);

        return $content;
    }

    public function delete(int $id): bool
    {
        $content = $this->findOrFail($id);

        // Check if deletion is allowed
        if (!$this->canDelete($content)) {
            throw new ContentException('Content cannot be deleted in current state');
        }

        // Delete content
        $result = $this->repository->delete($id);

        if ($result) {
            // Log deletion
            $this->auditService->logSecurityEvent('content_deleted', [
                'content_id' => $id,
                'type' => $content->type
            ]);

            // Dispatch deletion event
            Event::dispatch(new ContentDeleted($content));

            // Clear caches
            $this->clearContentCaches($content);
        }

        return $result;
    }

    public function publish(int $id): Content
    {
        $content = $this->findOrFail($id);

        // Validate content can be published
        if (!$this->canPublish($content)) {
            throw new ContentException('Content cannot be published in current state');
        }

        // Publish content
        $content = $this->repository->updateStatus($id, 'published');

        // Log publication
        $this->auditService->logSecurityEvent('content_published', [
            'content_id' => $content->id,
            'type' => $content->type
        ]);

        // Clear caches
        $this->clearContentCaches($content);

        return $content;
    }

    public function archive(int $id): Content
    {
        $content = $this->findOrFail($id);

        if (!$this->canArchive($content)) {
            throw new ContentException('Content cannot be archived in current state');
        }

        $content = $this->repository->updateStatus($id, 'archived');

        $this->auditService->logSecurityEvent('content_archived', [
            'content_id' => $content->id,
            'type' => $content->type
        ]);

        $this->clearContentCaches($content);

        return $content;
    }

    public function findOrFail(int $id): Content
    {
        $content = $this->repository->find($id);

        if (!$content) {
            throw new ContentException('Content not found');
        }

        return $content;
    }

    public function list(array $filters = []): array
    {
        return $this->repository->list($filters);
    }

    public function search(string $query, array $filters = []): array
    {
        return $this->repository->search($query, $this->searchableFields, $filters);
    }

    protected function canDelete(Content $content): bool
    {
        // Add business logic for deletion rules
        return $content->status !== 'published';
    }

    protected function canPublish(Content $content): bool
    {
        return $content->status === 'draft' && 
               $content->isComplete() &&
               !$content->hasBlockingIssues();
    }

    protected function canArchive(Content $content): bool
    {
        return in_array($content->status, ['published', 'draft']);
    }

    protected function clearContentCaches(Content $content): void
    {
        $cacheKeys = [
            "content:{$content->id}",
            "content:list",
            "content:type:{$content->type}",
            "content:category:{$content->category_id}"
        ];

        foreach ($cacheKeys as $key) {
            Cache::forget($key);
        }
    }
}
