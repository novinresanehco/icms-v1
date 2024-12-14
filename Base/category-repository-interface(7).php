<?php

namespace App\Core\Repositories\Contracts;

use App\Core\Models\Category;
use Illuminate\Support\Collection;

interface CategoryRepositoryInterface
{
    public function findById(int $id): ?Category;
    public function findBySlug(string $slug): ?Category;
    public function getTree(): Collection;
    public function getAllWithContent(): Collection;
    public function store(array $data): Category;
    public function update(int $id, array $data): ?Category;
    public function delete(int $id): bool;
    public function reorder(array $order): bool;
    public function moveToParent(int $id, ?int $parentId): ?Category;
}
