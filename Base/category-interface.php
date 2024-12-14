<?php

namespace App\Core\Contracts\Repositories;

use Illuminate\Database\Eloquent\{Model, Collection};

interface CategoryRepositoryInterface
{
    public function create(array $data): Model;
    public function update(int $id, array $data): Model;
    public function findById(int $id): Model;
    public function findBySlug(string $slug): Model;
    public function getActive(bool $withChildren = true): Collection;
    public function getRootCategories(): Collection;
    public function delete(int $id): bool;
    public function moveToParent(int $categoryId, ?int $parentId): Model;
    public function reorder(array $order): bool;
}
