<?php

namespace App\Services;

use App\Core\Contracts\TagRepositoryInterface;
use App\Models\Tag;
use App\Core\Exceptions\TagException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Validator;
use App\Http\Resources\TagResource;

class TagService
{
    protected TagRepositoryInterface $repository;

    /**
     * TagService constructor.
     *
     * @param TagRepositoryInterface $repository
     */
    public function __construct(TagRepositoryInterface $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Get all tags
     *
     * @return Collection
     */
    public function getAllTags(): Collection
    {
        return $this->repository->all();
    }

    /**
     * Create new tag
     *
     * @param array $data
     * @return TagResource
     * @throws TagException
     */
    public function createTag(array $data): TagResource
    {
        $this->validateTag($data);

        try {
            $tag = $this->repository->create($data);
            return new TagResource($tag);
        } catch (\Exception $e) {
            throw new TagException("Error creating tag: {$e->getMessage()}");
        }
    }

    /**
     * Update existing tag
     *
     * @param int $id
     * @param array $data
     * @return TagResource
     * @throws TagException
     */
    public function updateTag(int $id, array $data): TagResource
    {
        $this->validateTag($data, $id);

        try {
            $tag = $this->repository->update($id, $data);
            return new TagResource($tag);
        } catch (\Exception $e) {
            throw new TagException("Error updating tag: {$e->getMessage()}");
        }
    }

    /**
     * Delete tag
     *
     * @param int $id
     * @return bool
     * @throws TagException
     */
    public function deleteTag(int $id): bool
    {
        try {
            return $this->repository->delete($id);
        } catch (\Exception $e) {
            throw new TagException("Error deleting tag: {$e->getMessage()}");
        }
    }

    /**
     * Get popular tags
     *
     * @param int $limit
     * @return Collection
     */
    public function getPopularTags(int $limit = 10): Collection
    {
        return $this->repository->getPopularTags($limit);
    }

    /**
     * Get related tags
     *
     * @param Tag $tag
     * @param int $limit
     * @return Collection
     */
    public function getRelatedTags(Tag $tag, int $limit = 5): Collection
    {
        return $this->repository->getRelatedTags($tag, $limit);
    }

    /**
     * Sync content tags
     *
     * @param int $contentId
     * @param array $tagIds
     * @return void
     * @throws TagException
     */
    public function syncContentTags(int $contentId, array $tagIds): void
    {
        $this->repository->syncContentTags($contentId, $tagIds);
    }

    /**
     * Validate tag data
     *
     * @param array $data
     * @param int|null $id
     * @throws TagException
     */
    protected function validateTag(array $data, ?int $id = null): void
    {
        $rules = [
            'name' => 'required|string|max:255|unique:tags,name' . ($id ? ",$id" : ''),
            'description' => 'nullable|string|max:1000',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string|max:255'
        ];

        $validator = Validator::make($data, $rules);

        if ($validator->fails()) {
            throw new TagException('Tag validation failed: ' . $validator->errors()->first());
        }
    }
}
