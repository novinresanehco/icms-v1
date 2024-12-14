<?php

declare(strict_types=1);

namespace App\Repositories\Interfaces;

use App\Models\Template;
use Illuminate\Support\Collection;

interface TemplateRepositoryInterface
{
    /**
     * Find template by ID
     *
     * @param int $id
     * @return Template|null
     */
    public function findById(int $id): ?Template;

    /**
     * Find template by slug
     *
     * @param string $slug
     * @return Template|null
     */
    public function findBySlug(string $slug): ?Template;

    /**
     * Create new template
     *
     * @param array $data
     * @return Template
     */
    public function create(array $data): Template;

    /**
     * Update template
     *
     * @param int $id
     * @param array $data
     * @return bool
     */
    public function update(int $id, array $data): bool;

    /**
     * Delete template
     *
     * @param int $id
     * @return bool
     */
    public function delete(int $id): bool;

    /**
     * Get all templates
     *
     * @return Collection
     */
    public function getAll(): Collection;

    /**
     * Get templates by type
     *
     * @param string $type
     * @return Collection
     */
    public function getByType(string $type): Collection;

    /**
     * Find default template for type
     *
     * @param string $type
     * @return Template|null
     */
    public function findDefault(string $type = 'page'): ?Template;

    /**
     * Set default template for type
     *
     * @param int $id
     * @param string $type
     * @return bool
     */
    public function setDefault(int $id, string $type): bool;
}