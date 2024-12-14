<?php

namespace App\Services;

use App\Repositories\Contracts\ActivityRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ActivityService
{
    protected ActivityRepositoryInterface $activityRepository;
    
    public function __construct(ActivityRepositoryInterface $activityRepository)
    {
        $this->activityRepository = $activityRepository;
    }
    
    public function logActivity(string $type, array $data): ?int
    {
        $this->validateActivityData($data);
        return $this->activityRepository->log($type, $data);
    }
    
    public function getActivity(int $activityId): ?array
    {
        return $this->activityRepository->get($activityId);
    }
    
    public function getUserActivities(int $userId, int $perPage = 15): LengthAwarePaginator
    {
        return $this->activityRepository->getUserActivities($userId, $perPage);
    }
    
    public function getActivitiesByType(string $type, int $perPage = 15): LengthAwarePaginator
    {
        return $this->activityRepository->getByType($type, $perPage);
    }
    
    public function getRecentActivities(int $limit = 10): Collection
    {
        return $this->activityRepository->getRecent($limit);
    }
    
    public function searchActivities(array $criteria, int $perPage = 15): LengthAwarePaginator
    {
        $this->validateSearchCriteria($criteria);
        return $this->activityRepository->search($criteria, $perPage);
    }
    
    public function cleanupOldActivities(int $days): bool
    {
        if ($days < 1) {
            throw new \InvalidArgumentException('Days must be greater than 0');
        }
        return $this->activityRepository->deleteOlderThan($days);
    }
    
    public function getActivityStats(string $type, array $dateRange): array
    {
        $this->validateDateRange($dateRange);
        return $this->activityRepository->getStats($type, $dateRange);
    }
    
    protected function validateActivityData(array $data): void
    {
        $validator = Validator::make($data, [
            'subject_type' => 'nullable|string',
            'subject_id' => 'nullable|integer',
            'description' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
    }
    
    protected function validateSearchCriteria(array $criteria): void
    {
        $validator = Validator::make($criteria, [
            'user_id' => 'nullable|integer|exists:users,id',
            'type' => 'nullable|string',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'subject_type' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
    }
    
    protected function validateDateRange(array $dateRange): void
    {
        $validator = Validator::make($dateRange, [
            'start' => 'required|date',
            'end' => 'required|date|after_or_equal:start',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
    }
}
