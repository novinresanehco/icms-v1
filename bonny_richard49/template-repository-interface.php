<?php

namespace App\Core\Template\Repository;

use App\Core\Template\Models\Template;
use App\Core\Template\DTO\TemplateData;
use App\Core\Shared\Repository\RepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

interface TemplateRepositoryInterface extends RepositoryInterface
{
    /**
     * Find template by slug.
     *
     * @param string $slug
     * @return Template|null
     */
    public function findBySlug(string $slug): ?Template;

    /**
     * Get templates by type.
     *
     * @param string $type
     * @return Collection
     */
    public function getByType(string $type): Collection;

    /**
     * Get active templates.
     *
     * @return Collection
     */
    public function getActive(): Collection;

    /**
     * Get template variables.
     *
     * @param int $id
     * @return array
     */
    public function getVariables(int $id): array;

    /**
     * Set template as default for type.
     *
     * @param int $id
     * @param string $type
     * @return Template
     */
    public function setAsDefault(int $id, string $type): Template;

    /**
     * Get default template for type.
     *
     * @param string $type
     * @return Template|null
     */
    public function getDefaultForType(string $type): ?Template;

    /**
     * Duplicate template.
     *
     * @param int $id
     * @param array $overrides
     * @return Template
     */
    public function duplicate(int $id, array $overrides = []): Template;

    /**
     * Validate template syntax.
     *
     * @param string $content
     * @return array Any validation errors found
     */
    public function validateSyntax(string $content): array;

    /**
     * Get template usage statistics.
     *
     * @param int $id
     * @return array
     */
    public function getUsageStats(int $id): array;

    /**
     * Import template from file.
     *
     * @param string $path
     * @param array $data Additional template data
     * @return Template
     */
    public function importFromFile(string $path, array $data = []): Template;

    /**
     * Export template to file.
     *
     * @param int $id
     * @param string $path
     * @return bool
     */
    public function exportToFile(int $id, string $path): bool;

    /**
     * Process template inheritance.
     *
     * @param int $id
     * @return array Parent template hierarchy
     */
    public function getInheritanceChain(int $id): array;
}
