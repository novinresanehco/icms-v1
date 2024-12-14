<?php

namespace App\Core\Repositories\Contracts;

use App\Core\Models\Template;
use Illuminate\Support\Collection;

interface TemplateRepositoryInterface
{
    public function findById(int $id): ?Template;
    public function findBySlug(string $slug): ?Template;
    public function getActive(): Collection;
    public function getByTheme(string $theme): Collection;
    public function store(array $data): Template;
    public function update(int $id, array $data): ?Template;
    public function delete(int $id): bool;
    public function setDefault(int $id): bool;
}
