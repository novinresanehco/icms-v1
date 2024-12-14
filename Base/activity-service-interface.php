<?php

namespace App\Core\Services\Contracts;

use App\Core\Models\Activity;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

interface ActivityServiceInterface
{
    public function getActivity(int $id): ?Activity;
    
    public function getSubjectActivities(string $subjectType, int $subjectId): Collection;
    
    public function getCauserActivities(string $causerType, int $causerId): Collection;
    
    public function getLatestActivities(int $limit = 50): Collection;
    
    public function getActivitiesByType(string $type, int $perPage = 15): LengthAwarePaginator;
    
    public function logActivity(array $data): Activity;
    
    public function deleteActivity(int $id): bool;
    
    public function deleteSubjectActivities(string $subjectType, int $subjectId): bool;
    
    public function deleteCauserActivities(string $causerType, int $causerId): bool;
}
