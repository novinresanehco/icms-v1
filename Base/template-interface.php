<?php

namespace App\Core\Contracts\Repositories;

use Illuminate\Database\Eloquent\{Model, Collection};

interface TemplateRepositoryInterface
{
    public function create(array $data): Model;
    public function update(int $id, array $data): Model;
    public function findById(int $id): Model;
    public function findBySlug(string $slug): Model;
    public function findByType(string $type): Collection;
    public function getActiveTemplates(): Collection;
    public function compile(int $id, array $data = []): string;
    public function delete(int $id): bool;
    public function duplicate(int $id, array $data = []): Model;
}
