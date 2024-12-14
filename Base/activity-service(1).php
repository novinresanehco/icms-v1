<?php

namespace App\Core\Services;

use App\Core\Models\Activity;
use App\Core\Services\Contracts\ActivityServiceInterface;
use App\Core\Repositories\Contracts\ActivityRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Pagination\LengthAwarePaginator;

class ActivityService implements ActivityServiceInterface
{
    public function __construct(
        private ActivityRepositoryInterface $repository
    ) {}

    public function getActivity(int $id): ?Activity
    {
        return Cache::tags(['activities'])->remember(
            "activities.{$id}",
            now()->addHour(),
            fn() => $this->repository->findById($id)
        );
    }

    public function getSubjectActivities(string $subjectType, int $subjectId): Collection
    {
        return Cache::tags(['activities'])->remember(
            "activities.subject.{$subjectType}.{$subjectId}",
            now()->addHour(),
            fn() => $this->repository->getForSubject($subjectType, $subjectId)
        );
    }

    public function getCauserActivities(string $causerType, int $causerId): Collection
    {
        return Cache::tags(['activities'])->remember(
            "activities.causer.{$causerType}.{$causerId}",
            now()->addHour(),
            fn() => $this->repository->getByCauser($causerType, $causerId)
        );
    }

    public function getLatestActivities(int $limit = 50): Collection
    {
        return Cache::tags(['activities'])->remember(
            "activities.latest.{$limit}",
            now()->addMinutes(5),
            fn() => $this->repository->getLatest($limit)
        );
    }

    public function getActivitiesByType(string $type, int $perPage = 15): LengthAwarePaginator
    {
        return $this->repository->getByType($type, $perPage);
    }

    public function logActivity(array $data): Activity
    {
        $activity = $this->repository->store($data);
        Cache::tags(['activities'])->flush();
        return $activity;
    }

    public function deleteActivity(int $id): bool
    {
        $result = $this->repository->delete($id);
        Cache::tags(['activities'])->flush();
        return $result;
    }

    public function deleteSubjectActivities(string $subjectType, int $subjectId): bool
    {
        $result = $this->repository->deleteForSubject($subjectType, $subjectId);
        Cache::tags(['activities'])->flush();
        return $result;
    }

    public function deleteCauserActivities(string $causerType, int $causerId): bool
    {
        $result = $this->repository->deleteByCauser($causerType, $causerId);
        Cache::tags(['activities'])->flush();
        return $result;
    }
}
