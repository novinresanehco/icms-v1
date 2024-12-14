<?php

namespace App\Core\Tag\Contracts;

use App\Core\Tag\Models\Tag;
use Illuminate\Support\Collection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface TagRepositoryInterface
{
    /**
     * Create a new tag.
     */
    public function create(array $data): Tag;

    /**
     * Update an existing tag.
     */
    public function update(int $id, array $data): Tag;

    /**
     * Delete a tag.
     */
    public function delete(int $id): bool;

    /**
     * Find a tag by ID.
     */
    public function find(int $id): ?Tag;

    /**
     * Get paginated list of tags.
     */
    public function getPaginated(array $filters = [], int $perPage = 15): LengthAwarePaginator;

    /**
     * Search tags based on criteria.
     */
    public function search(array $criteria): Collection;
}

interface TagServiceInterface
{
    /**
     * Handle tag creation.
     */
    public function createTag(array $data): Tag;

    /**
     * Handle tag update.
     */
    public function updateTag(int $id, array $data): Tag;

    /**
     * Handle tag deletion.
     */
    public function deleteTag(int $id): bool;

    /**
     * Get tag with relationships.
     */
    public function getTagWithRelations(int $id, array $relations = []): ?Tag;

    /**
     * Handle tag search.
     */
    public function searchTags(array $criteria): Collection;
}

interface TagCacheInterface
{
    /**
     * Get tag from cache.
     */
    public function get(int $id): ?Tag;

    /**
     * Store tag in cache.
     */
    public function put(Tag $tag): void;

    /**
     * Remove tag from cache.
     */
    public function forget(int $id): void;

    /**
     * Clear all tag caches.
     */
    public function flush(): void;
}

interface TagValidatorInterface
{
    /**
     * Validate tag data.
     */
    public function validate(array $data): bool;

    /**
     * Get validation errors.
     */
    public function getErrors(): array;
}
