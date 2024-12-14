<?php

declare(strict_types=1);

namespace App\Repositories\Interfaces;

use App\Models\Tag;
use Illuminate\Support\Collection;

interface TagRepositoryInterface
{
    /**
     * Find tag by ID
     *
     * @param int $id
     * @return Tag|null
     */
    public function findById(int $id): ?Tag;

    /**
     * Find tag by slug
     *
     * @param string $slug
     * @return Tag|null
     */
    public function findBySlug(string $slug): ?Tag;

    /**
     * Get all tags
     *
     * @return Collection
     */
    public function getAll(): Collection;

    /**
     * Get popular tags
     *
     * @param int $limit
     * @return Collection
     */
    public function getPopular(int $limit = 10): Collection;

    /**
     * Create new tag
     *
     * @param array $data
     * @return Tag
     */
    public function create(array $data): Tag;

    /**
     * Update tag
     *
     * @param int $id
     * @param array $data
     * @return bool
     */
    public function update(int $id, array $data): bool;

    /**
     * Delete tag
     *
     * @param int $id
     * @return bool
     */
    public function delete(int $id): bool;

    /**
     * Find or create tag by name
     *
     * @param string $name
     * @return Tag
     */
    public function findOrCreate(string $name): Tag;

    /**
     * Find tags by type
     *
     * @param string $type
     * @return Collection
     */
    public function findByType(string $type): Collection;
}