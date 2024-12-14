<?php

namespace App\Core\Repositories;

use App\Models\Schedule;
use App\Core\Services\Cache\CacheService;
use Illuminate\Support\Collection;
use Carbon\Carbon;

class ScheduleRepository extends AdvancedRepository
{
    protected $model = Schedule::class;
    protected $cache;

    public function __construct(CacheService $cache)
    {
        parent::__construct();
        $this->cache = $cache;
    }

    public function scheduleContent($content, Carbon $publishAt, ?Carbon $unpublishAt = null): Schedule
    {
        return $this->executeTransaction(function() use ($content, $publishAt, $unpublishAt) {
            return $this->create([
                'schedulable_type' => get_class($content),
                'schedulable_id' => $content->id,
                'action' => 'publish',
                'scheduled_at' => $publishAt,
                'end_at' => $unpublishAt,
                'status' => 'pending',
                'created_by' => auth()->id()
            ]);
        });
    }

    public function getPendingSchedules(): Collection
    {
        return $this->executeQuery(function() {
            return $this->model
                ->where('status', 'pending')
                ->where('scheduled_at', '<=', now())
                ->get();
        });
    }

    public function markAsCompleted(Schedule $schedule): void
    {
        $this->executeTransaction(function() use ($schedule) {
            $schedule->update([
                'status' => 'completed',
                'completed_at' => now()
            ]);
        });
    }

    public function markAsFailed(Schedule $schedule, string $reason): void
    {
        $this->executeTransaction(function() use ($schedule, $reason) {
            $schedule->update([
                'status' => 'failed',
                'failure_reason' => $reason,
                'failed_at' => now()
            ]);
        });
    }

    public function getUpcomingSchedules(?string $type = null): Collection
    {
        return $this->executeQuery(function() use ($type) {
            $query = $this->model
                ->where('status', 'pending')
                ->where('scheduled_at', '>', now());

            if ($type) {
                $query->where('schedulable_type', $type);
            }

            return $query->orderBy('scheduled_at')->get();
        });
    }
}
