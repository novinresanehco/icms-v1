<?php

namespace App\Services;

use App\Models\Content;
use App\Repositories\Contracts\ContentRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ContentService
{
    public function __construct(
        protected ContentRepositoryInterface $contentRepository
    ) {}

    public function create(array $data): Content
    {
        $this->validateContentData($data);
        return $this->contentRepository->create($data);
    }

    public function update(int $id, array $data): Content
    {
        $this->validateContentData($data, $id);
        return $this->contentRepository->update($id, $data);
    }

    public function delete(int $id): bool
    {
        return $this->contentRepository->delete($id);
    }

    public function find(int $id): ?Content
    {
        return $this->contentRepository->find($id);
    }

    public function findBySlug(string $slug): ?Content
    {
        return $this->contentRepository->findBySlug($slug);
    }

    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return $this->contentRepository->paginate($perPage);
    }

    public function getPublished(): Collection
    {
        return $this->contentRepository->getPublished();
    }

    public function getDrafts(): Collection
    {
        return $this->contentRepository->getDrafts();
    }

    public function search(string $query): Collection
    {
        return $this->contentRepository->search($query);
    }

    public function findByType(string $type): Collection
    {
        return $this->contentRepository->findByType($type);
    }

    public function getByCategory(int $categoryId): Collection
    {
        return $this->contentRepository->getByCategory($categoryId);
    }

    public function getByTags(array $tags): Collection
    {
        return $this->contentRepository->getByTags($tags);
    }

    public function createVersion(int $contentId, array $data): bool
    {
        return $this->contentRepository->createVersion($contentId, $data);
    }

    public function getVersions(int $contentId): Collection
    {
        return $this->contentRepository->getVersions($contentId);
    }

    public function revertToVersion(int $contentId, int $versionId): Content
    {
        return $this->contentRepository->revertToVersion($contentId, $versionId);
    }

    protected function validateContentData(array $data, ?int $id = null): void
    {
        $rules = [
            'type' => 'required|string|max:50',
            'title' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:contents,slug' . ($id ? ",$id" : ''),
            'content' => 'required|string',
            'excerpt' => 'nullable|string',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string',
            'featured_image' => 'nullable|string|max:255',
            'status' => 'required|in:draft,published',
            'published_at' => 'nullable|date',
            'author_id' => 'required|exists:users,id',
            'template' => 'nullable|string|max:50',
            'order' => 'nullable|integer',
            'parent_id' => 'nullable|exists:contents,id',
            'settings' => 'nullable|array',
            'categories' => 'nullable|array',
            'categories.*' => 'exists:categories,id',
            'tags' => 'nullable|array',
            'tags.*' => 'exists:tags,id'
        ];

        $validator = Validator::make($data, $rules);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
    }
}
