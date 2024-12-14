<?php

namespace App\Core\Contracts;

use App\Models\Content;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface ContentRepositoryInterface extends RepositoryInterface
{
    /**
     * Get published content
     *
     * @param array $columns
     * @param array $relations
     * @return Collection
     */
    public function getPublished(array $columns = ['*'], array $relations = []): Collection;

    /**
     * Find content by slug
     *
     * @param string $slug
     * @param array $relations
     * @return Content|null
     */
    public function findBySlug(string $slug, array $relations = []): ?Content;

    /**
     * Create content with associated relations
     *
     * @param array $attributes
     * @return Content
     */
    public function createWithRelations(array $attributes): Content;

    /**
     * Update content and its relations
     *
     * @param int $id
     * @param array $attributes
     * @return Content
     */
    public function updateWithRelations(int $id, array $attributes): Content;

    /**
     * Get content with specific tag
     *
     * @param string $tag
     * @param array $columns
     * @return Collection
     */
    public function getByTag(string $tag, array $columns = ['*']): Collection;
}
