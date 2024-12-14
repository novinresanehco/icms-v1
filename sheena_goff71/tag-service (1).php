<?php

namespace App\Core\Tag\Services;

use App\Core\Tag\Contracts\TagRepositoryInterface;
use App\Core\Tag\Exceptions\TagValidationException;
use App\Core\Tag\Models\Tag;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

class TagService
{
    /**
     * @var TagRepositoryInterface
     */
    protected TagRepositoryInterface $repository;

    /**
     * @param TagRepositoryInterface $repository
     */
    public function __construct(TagRepositoryInterface $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Create a new tag.
     *
     * @param array $data
     * @return Tag
     * @throws TagValidationException
     */
    public function create(array $data): Tag
    {
        $this->validateTag($data);

        $tag = $this->repository->create($data);

        $this->clearTagCache();

        return $tag;
    }

    /**
     * Update an existing tag.
     *
     * @param int $id
     * @param array $data
     * @return Tag
     * @throws TagValidationException
     */
    public function update(int $id, array $data): Tag
    {
        $this->validateTag($data);

        $tag = $this->repository->update($id, $data);

        $this->clearTagCache();

        return $tag;
    }

    /**
     * Delete a tag.
     *
     * @param int $id
     * @return bool
     */
    public function delete(int $id): bool
    {
        $result = $this->repository->delete($id);

        if ($result) {
            $this->clearTagCache();
        }

        return $result;
    }

    /**
     * Get content tags.
     *
     * @param int $contentId
     * @return Collection
     */
    public function getContentTags(int $contentId): Collection
    {
        return Cache::tags(['tags', "content:{$contentId}"])
            ->remember(
                "content:{$contentId}:tags",
                now()->addHours(24),
                fn() => $this->repository->getContentTags($contentId)
            );
    }

    /**
     * Attach tags to content.
     *
     * @param int $contentId
     * @param array $tagIds
     * @return void
     */
    public function attachToContent(int $contentId, array $tagIds): void
    {
        $this->repository->attachToContent($contentId, $tagIds);
        
        Cache::tags(['tags', "content:{$contentId}"])->flush();
    }

    /**
     * Get popular tags.
     *
     * @param int $limit
     * @return Collection
     */
    public function getPopularTags(int $limit = 10): Collection
    {
        return Cache::tags(['tags', 'popular'])
            ->remember(
                'popular_tags',
                now()->addHours(6),
                fn() => $this->repository->getPopularTags($limit)
            );
    }

    /**
     * Merge tags.
     *
     * @param int $sourceTagId
     * @param int $targetTagId
     * @return Tag
     */
    public function mergeTags(int $sourceTagId, int $targetTagId): Tag
    {
        $tag = $this->repository->mergeTags($sourceTagId, $targetTagId);
        
        $this->clearTagCache();
        
        return $tag;
    }

    /**
     * Validate tag data.
     *
     * @param array $data
     * @throws TagValidationException
     */
    protected function validateTag(array $data): void
    {
        $validator = Validator::make($data, [
            'name' => 'required|string|max:255|unique:tags,name,' . ($data['id'] ?? ''),
            'description' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            throw new TagValidationException($validator);
        }
    }

    /**
     * Clear tag-related cache.
     *
     * @return void
     */
    protected function clearTagCache(): void
    {
        Cache::tags(['tags'])->flush();
    }
}
