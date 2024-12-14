<?php

namespace App\Core\Repositories\Contracts;

use App\Core\Models\Template;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

interface TemplateRepositoryInterface
{
    /**
     * Find template by ID
     */
    public function find(int $id): ?Template;
    
    /**
     * Get all templates with optional filtering
     */
    public function all(array $filters = []): Collection;
    
    /**
     * Get paginated templates
     */
    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator;
    
    /**
     * Create new template
     */
    public function create(array $data): Template;
    
    /**
     * Update existing template
     */
    public function update(Template $template, array $data): bool;
    
    /**
     * Delete template
     */
    public function delete(Template $template): bool;
    
    /**
     * Find template by slug
     */
    public function findBySlug(string $slug): ?Template;
    
    /**
     * Get templates by type
     */
    public function getByType(string $type, array $filters = []): Collection;
    
    /**
     * Get templates by category
     */
    public function getByCategory(string $category, array $filters = []): Collection;
    
    /**
     * Get active templates
     */
    public function getActive(array $filters = []): Collection;
}
