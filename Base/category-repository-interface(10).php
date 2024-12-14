<?php

namespace App\Core\Repositories\Contracts;

use App\Core\Models\Category;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

interface CategoryRepositoryInterface
{
    public function find(int $id): ?Category;
    public function findBySlug(string $slug): ?Category;
    public function all(array $filters = []): Collection;
    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator;
    public function create(array $data): Category;
    public function update(Category $category, array $data): bool;
    public function delete(Category $category): bool;
    public function getTree(): Collection;
    public function getChildren(int $parentId): Collection;
    public function reorder(array $order): void;
    public function moveToParent(Category $category, ?int $parentId): bool;
}
