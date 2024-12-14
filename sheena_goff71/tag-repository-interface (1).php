<?php

namespace App\Core\Tag\Contracts;

use App\Core\Tag\Models\Tag;
use Illuminate\Support\Collection;
use App\Core\Tag\Exceptions\TagNotFoundException;

interface TagRepositoryInterface
{
    /**
     * Create a new tag.
     *
     * @param array $data
     * @return Tag
     */
    public function create(array $data): Tag;

    /**
     * Update an existing tag.
     *
     * @param int $id
     * @param array $data
     * @return Tag
     * @throws TagNotFoundException
     */
    public function update(int $id, array $data): Tag;

    /**
     * Delete a tag.
     *
     * @param int $id
     * @return bool
     * @throws TagNotFoundException
     */
    public function delete(int $id): bool;

    /**
     * Find a tag by ID.
     *
     * @param int $id
     * @return Tag|null
     */
    public function find(int $id): ?Tag;

    /**
     * Find a tag by ID or throw an exception.
     *
     * @param int $id
     * @return Tag
     * @throws TagNotFoundException
     */
    public function findOrFail(int $id): Tag;

    /**
     * Attach tags to content.
     *
     * @param int $contentId
     * @param array $tagIds
     * @return void
     */
    public function attachToContent(int $contentId, array $tagIds): void;

    /**
     * Detach tags from content.
     *
     * @param int $contentId
     * @param array $tagIds
     * @return void
     */
    public function detachFromContent(int $contentId, array $tagIds): void;

    /**
     * Get content tags.
     *
     * @param int $contentId
     * @return Collection
     */
    public function getContentTags(int $contentId): Collection;

    /**
     * Merge tags.
     *
     * @param int $sourceTagId
     * @param int $targetTagId
     * @return Tag
     */
    public function mergeTags(int $sourceTagId, int $targetTagId): Tag;

    /**
     * Search tags by name.
     *
     * @param string $name
     * @return Collection
     */
    public function searchByName(string $name): Collection;

    /**
     * Get popular tags.
     *
     * @param int $limit
     * @return Collection
     */
    public function getPopularTags(int $limit = 10): Collection;
}
