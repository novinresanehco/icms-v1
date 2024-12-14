<?php

namespace App\Repositories\Contracts;

use App\Models\Template;
use Illuminate\Support\Collection;

interface TemplateRepositoryInterface
{
    public function findBySlug(string $slug): ?Template;
    public function findWithComponents(int $id): ?Template;
    public function getActiveTemplates(): Collection;
    public function createWithComponents(array $data, array $components): Template;
    public function updateWithComponents(int $id, array $data, array $components): bool;
    public function getTemplatesByType(string $type): Collection;
    public function getTemplateVersions(int $id): Collection;
    public function activateTemplate(int $id): bool;
    public function deactivateTemplate(int $id): bool;
    public function duplicateTemplate(int $id, string $newName): Template;
}
