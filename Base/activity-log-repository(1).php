<?php

namespace App\Repositories;

use App\Models\ActivityLog;
use App\Core\Repositories\BaseRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class ActivityLogRepository extends BaseRepository
{
    public function __construct(ActivityLog $model)
    {
        $this->model = $model;
        parent::__construct();
    }

    public function log(
        string $action, 
        ?Model $subject = null, 
        array $properties = []
    ): ActivityLog {
        return $this->create([
            'user_id' => auth()->id(),
            'action' => $action,
            'subject_type' => $subject ? get_class($subject) : null,
            'subject_id' => $subject ? $subject->getKey() : null,
            'properties' => $properties,
            'ip_address' => request()->ip()
        ]);
    }

    public function findByUser(int $userId, int $limit = 20): Collection
    {
        return $this->executeWithCache(__FUNCTION__, [$userId, $limit], function () use ($userId, $limit) {
            return $this->model->where('user_id', $userId)
                             ->orderBy('created_at', 'desc')
                             ->limit($limit)
                             ->get();
        });
    }

    public function findBySubject(Model $subject): Collection
    {
        return $this->executeWithCache(__FUNCTION__, [get_class($subject), $subject->getKey()], function () use ($subject) {
            return $this->model->where('subject_type', get_class($subject))
                             ->where('subject_id', $subject->getKey())
                             ->orderBy('created_at', 'desc')
                             ->get();
        });
    }

    public function findByAction(string $action): Collection
    {
        return $this->executeWithCache(__FUNCTION__, [$action], function () use ($action) {
            return $this->model->where('action', $action)
                             ->orderBy('created_at', 'desc')
                             ->get();
        });
    }

    public function purgeOldLogs(int $days = 30): int
    {
        $count = $this->model->where('created_at', '<', now()->subDays($days))->delete();
        $this->clearCache();
        return $count;
    }
}
