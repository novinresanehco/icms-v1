<?php

namespace App\Services;

use App\Repositories\Contracts\ContentRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ContentService
{
    protected ContentRepositoryInterface $contentRepository;
    
    public function __construct(ContentRepositoryInterface $contentRepository)
    {
        $this->contentRepository = $contentRepository;
    }
    
    public function createContent(array $data): ?int
    {
        $this->validateContentData($data);
        return $this->contentRepository->create($data);
    }
    
    public function updateContent(int $contentId, array $data): bool
    {
        $this->validateContentData($data);
        return $this->contentRepository->update($contentId, $data);
    }
    
    public function deleteContent(int $contentId): bool
    {
        return $this->contentRepository->delete($contentId);
    }
    
    public function getContent(int $contentId, array $relations = []): ?array
    {
        return $this->contentRepository->get($contentId, $relations);
    }
    
    public function getContentBySlug(string $slug, array $relations = []): ?array
    {
        return $this->contentRepository->getBySlug($slug, $relations);
    }
    
    public function getAllContentPaginated(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->contentRepository->getAllPaginated($filters, $perPage);
    }
    
    public function getPublishedContentByType(string $type, int $limit = 10): Collection
    {
        return $this->contentRepository->getPublishedByType($type, $limit);
    }
    
    public function searchContent(string $query, array $types = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->contentRepository->search($query, $types, $perPage);
    }
    
    public function publishContent(int $contentId): bool
    {
        return $this->contentRepository->publishContent($contentId);
    }
    
    public function unpublishContent(int $contentId): bool
    {
        return $this->contentRepository->unpublishContent($contentId);
    }
    
    public function updateContentMetadata(int $contentId, array $metadata): bool
    {
        return $this->contentRepository->updateMetadata($contentId, $metadata);
    }
    
    protected function validateContentData(array $data): void
    {
        $validator = Validator::make($data, [
            'title' => 'required|string|max:255',
            'slug' => 'required|string|max:255',
            'excerpt' => 'nullable|string',
            'content' => 'required|string',
            'type' => 'required|string|max:50',
            'template' => 'nullable|string|max:100',
            'author_id' => 'required|exists:users,id',
            'status' => 'boolean',
            'published_at' => 'nullable|date',
            'metadata' => 'array',
            '