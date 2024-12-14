<?php

namespace App\Core\Repositories\Contracts;

use App\Core\Models\Activity;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

interface ActivityRepositoryInterface
{
    public function findById(int $id): ?Activity;
    
    public function getForSubject(string $subjectType, int $subjectId): Collection;
    
    public function getByCauser(string $causerType, int $causerId): Collection;
    
    public function getLatest(int $limit = 50): Collection;
    
    public function getByType(string $type, int $perPage = 15): LengthAwarePaginator;
    
    public function store(array $data): Activity;
    
    public function delete(int $id): bool;
    
    public function deleteForSubject(string $subjectType, int $subjectId): bool;
    
    public function deleteByCauser(string $causerType, int $causerId): bool;
}
