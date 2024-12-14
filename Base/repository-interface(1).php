<?php

namespace App\Core\Repositories\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

interface RepositoryInterface
{
    public function all(array $columns = ['*']): Collection;
    
    public function paginate(int $perPage = 15, array $columns = ['*']): LengthAwarePaginator;
    
    public function create(array $data): Model;
    
    public function update(int $id, array $data): bool;
    
    public function delete(int $id): bool;
    
    public function find(int $id, array $columns = ['*']): ?Model;
    
    public function findBy(string $field, mixed $value, array $columns = ['*']): ?Model;
    
    public function findWhere(array $criteria, array $columns = ['*']): Collection;
    
    public function findWhereIn(string $field, array $values, array $columns = ['*']): Collection;
}
