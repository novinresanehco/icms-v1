<?php

namespace App\Core\Repositories;

use App\Models\Activity;
use Illuminate\Support\Collection;

class ActivityRepository extends AdvancedRepository
{
    protected $model = Activity::class;

    public function logActivity(string $action, $subject = null, array $properties = []): Activity
    {
        return $this->executeTransaction(function() use ($action, $subject, $properties) {
            return $this->create([
                'user_id' => auth()->id(),
                'action' => $action,
                'subject_type' => $subject ? get_class($subject) : null,
                'subject_id' => $subject ? $subject->id : null,
                'properties' => $properties,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'created_at' => now()
            ]);
        });
    }

    public function getUserActivity(int $userId): Collection
    {
        return $this->executeQuery(function() use ($userId) {
            return $this->model
                ->where('user_id', $userId)
                ->orderBy('created_at', 'desc')
                ->get();
        });
    }

    public function getSubjectActivity($subject): Collection
    {
        return $this->executeQuery(function() use ($subject) {
            return $this->model
                ->where('subject_type', get_class($subject))
                ->where('subject_id', $subject->id)
                ->orderBy('created_at', 'desc')
                ->get();
        });
    }

    public function getRecentActivity(int $limit = 50): Collection
    {
        return $this->executeQuery(function() use ($limit) {
            return $this->model
                ->with(['user', 'subject'])
                ->orderBy('created_at', 'desc')
                ->limit($limit)
                ->get();
        });
    }

    public function pruneOldActivity(int $days = 90): int
    {
        return $this->executeTransaction(function() use ($days) {
            return $this->model
                ->where('created_at', '<=', now()->subDays($days))
                ->delete();
        });
    }
}
