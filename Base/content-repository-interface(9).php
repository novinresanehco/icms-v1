<?php

namespace App\Core\Contracts\Repositories;

use Illuminate\Database\Eloquent\{Model, Collection};

interface ContentRepositoryInterface
{
    /**
     * Create new content
     */
    public function create(array $data): Model;

    /**
     * Update existing content
     */
    public function update(int $id, array $data): Model;

    /**
     * Find content by ID
     */
    public function findById(int $id): Model;

    /**
     * Find content by slug
     */
    public function findBySlug(string $slug): Model;

    /**
     * Get published content
     */
    public function getPublished(int $perPage = 15): Collection;

    /**
     * Search content by criteria
     */
    public function search(array $criteria): Collection;

    /**
     * Delete content by ID
     */
    public function delete(int $id): bool;
}
