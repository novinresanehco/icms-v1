<?php

namespace App\Core\Repositories\Contracts;

use App\Core\Models\Category;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

interface CategoryRepositoryInterface
{
    public function findById(int $id): ?Category;
    public function create(array $data): Category;
    public function update(Category $category, array $data): bool;
    public function delete(int $id): bool;
    public function getRoots(): Collection;
    public function getByType(string $type): Collection;
    public function getWithChildren(int $id): Category;
    public function reorder(array $order): bool;
    public function getTree(): Collection;
    public function paginate(int $perPage = 15): LengthAwarePaginator;
}
