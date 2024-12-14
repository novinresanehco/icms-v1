<?php

namespace App\Repositories\Contracts;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface ActivityRepositoryInterface
{
    public function log(string $type, array $data): ?int;
    
    public function get(int $activityId): ?array;
    
    public function getUserActivities(int $userId, int $perPage = 15): LengthAwarePaginator;
    
    public function getByType(string $type, int $perPage = 15): LengthAwarePaginator;
    
    public function getRecent(int $limit = 10): Collection;
    
    public function search(array $criteria, int $perPage = 15): LengthAwarePaginator;
    
    public function deleteOlderThan(int $days): bool;
    
    public function getStats(string $type, array $dateRange): array;
}
