<?php

namespace App\Core\Contracts\Repositories;

use App\Core\Models\Category;
use Illuminate\Database\Eloquent\Collection;

interface CategoryRepositoryInterface
{
    public function getAllCategories(bool $useCache = true): Collection;
    
    public function findById(int $id): Category;
    
    public function create(array $data): Category;
    
    public function update(int $id, array $data): Category;
    
    public function delete(int $id): bool;
}
