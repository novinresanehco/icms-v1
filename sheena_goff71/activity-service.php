<?php

namespace App\Core\Activity\Services;

use App\Core\Activity\Models\Activity;
use App\Core\Activity\Repositories\ActivityRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

class ActivityService
{
    public function __construct(
        private ActivityRepository $repository,
        private ActivityValidator $validator
    ) {}

    public function log(string $type, array $data = [], ?string $subject = null): Activity
    {
        $this->validator->validateLog($type, $data);

        $activity = $this->repository->create([
            'type' => $type,
            'data' => $data,
            'subject' => $subject,
            'user_id' => Auth::id(),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent()
        ]);

        if (config('activity.broadcast_events', false)) {
            event(new ActivityLogged($activity));
        }

        return $activity;
    }

    public function getAll(array $filters = []): Collection
    {
        return $this->repository->getWithFilters($filters);
    }

    public function getUserActivity(int $userId, array $filters = []): Collection
    {
        return $this->repository->getUserActivity($userId, $filters);
    }

    public function getByType(string $type, array $filters = []): Collection
    {
        return $this->repository->getByType($type, $filters);
    }

    public function getBySubject(string $subject, array $filters = []): Collection
    {
        return $this->repository->getBySubject($subject, $filters);
    }

    public function getStats(array $filters = []): array
    {
        return $this->repository->getActivityStats($filters);
    }

    public function cleanOldRecords(int $days = 30): int
    {
        return $this->repository->deleteOlderThan($days);
    }

    public function export(array $filters = []): array
    {
        return $this->repository->exportActivities($filters);
    }
}
