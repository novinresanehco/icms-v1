<?php

namespace App\Core\Notification\Analytics\Scheduling;

use App\Core\Notification\Analytics\Models\AnalyticsSchedule;
use App\Core\Notification\Analytics\Jobs\GenerateAnalyticsReport;
use App\Core\Notification\Analytics\Events\ScheduleCreatedEvent;
use Illuminate\Support\Facades\{Cache, Log};
use Carbon\Carbon;

class AnalyticsScheduler
{
    private const CACHE_PREFIX = 'analytics_schedule:';
    private const DEFAULT_RETRY_ATTEMPTS = 3;

    private array $config;
    private array $activeSchedules = [];

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'max_concurrent_jobs' => 5,
            'retry_attempts' => self::DEFAULT_RETRY_ATTEMPTS,
            'retry_delay' => 300, // 5 minutes
            'cache_ttl' => 3600   // 1 hour
        ], $config);
    }

    public function schedule(array $data): AnalyticsSchedule
    {
        $schedule = new AnalyticsSchedule([
            'name' => $data['name'],
            'type' => $data['type'],
            'frequency' => $data['frequency'],
            'parameters' => $data['parameters'] ?? [],
            'next_run' => $this->calculateNextRun($data['frequency']),
            'status' => 'active',
            'metadata' => array_merge($data['metadata'] ?? [], [
                'created_at' => now(),
                'created_by' => auth()->id() ?? 'system'
            ])
        ]);

        $schedule->save();
        $this->cacheSchedule($schedule);
        
        event(new ScheduleCreatedEvent($schedule));

        return $schedule;
    }

    public function dispatchDueJobs(): array
    {
        $results = [];
        $dueSchedules = $this->getDueSchedules();

        foreach ($dueSchedules as $schedule) {
            try {
                if ($this->canDispatch($schedule)) {
                    $job = new GenerateAnalyticsReport($schedule);
                    dispatch($job);

                    $results[$schedule->id] = [
                        'status' => 'dispatched',
                        'next_run' => $this->updateNextRun($schedule)
                    ];
                } else {
                    $results[$schedule->id] = [
                        'status' => 'skipped',
                        'reason' => 'concurrent_limit_reached'
                    ];
                }
            } catch (\Exception $e) {
                Log::error('Failed to dispatch analytics job', [
                    'schedule_id' => $schedule->id,
                    'error' => $e->getMessage()
                ]);

                $results[$schedule->id] = [
                    'status' => 'failed',
                    'error' => $e->getMessage()
                ];
            }
        }

        return $results;
    }

    public function pauseSchedule(int $scheduleId): bool
    {
        $schedule = AnalyticsSchedule::findOrFail($scheduleId);
        $schedule->status = 'paused';
        $schedule->save();

        $this->invalidateScheduleCache($scheduleId);
        return true;
    }

    public function resumeSchedule(int $scheduleId): bool
    {
        $schedule = AnalyticsSchedule::findOrFail($scheduleId);
        $schedule->status = 'active';
        $schedule->next_run = $this->calculateNextRun($schedule->frequency);
        $schedule->save();

        $this->cacheSchedule($schedule);
        return true;
    }

    public function deleteSchedule(int $scheduleId): bool
    {
        $schedule = AnalyticsSchedule::findOrFail($scheduleId);
        $schedule->delete();

        $this->invalidateScheduleCache($scheduleId);
        return true;
    }

    private function getDueSchedules(): array
    {
        return AnalyticsSchedule::where('status', 'active')
            ->where('next_run', '<=', now())
            ->get()
            ->all();
    }

    private function canDispatch(AnalyticsSchedule $schedule): bool
    {
        $activeJobs = Cache::get(self::CACHE_PREFIX . 'active_jobs', 0);
        return $activeJobs < $this->config['max_concurrent_jobs'];
    }

    private function calculateNextRun(string $frequency): Carbon
    {
        return match($frequency) {
            'hourly' => now()->addHour(),
            'daily' => now()->addDay(),
            'weekly' => now()->addWeek(),
            'monthly' => now()->addMonth(),
            default => throw new \InvalidArgumentException("Invalid frequency: {$frequency}")
        };
    }

    private function updateNextRun(AnalyticsSchedule $schedule): Carbon
    {
        $nextRun = $this->calculateNextRun($schedule->frequency);
        $schedule->next_run = $nextRun;
        $schedule->save();

        $this->cacheSchedule($schedule);
        return $nextRun;
    }

    private function cacheSchedule(AnalyticsSchedule $schedule): void
    {
        Cache::put(
            self::CACHE_PREFIX . $schedule->id,
            $schedule,
            now()->addSeconds($this->config['cache_ttl'])
        );
    }

    private function invalidateScheduleCache(int $scheduleId): void
    {
        Cache::forget(self::CACHE_PREFIX . $scheduleId);
    }

    public function getActiveSchedules(): array
    {
        return AnalyticsSchedule::where('status', 'active')->get()->all();
    }

    public function getScheduleStatus(int $scheduleId): array
    {
        $schedule = AnalyticsSchedule::findOrFail($scheduleId);
        $lastRun = $schedule->metadata['last_run'] ?? null;

        return [
            'id' => $schedule->id,
            'name' => $schedule->name,
            'status' => $schedule->status,
            'last_run' => $lastRun,
            'next_run' => $schedule->next_run,
            'frequency' => $schedule->frequency,
            'success_count' => $schedule->metadata['success_count'] ?? 0,
            'failure_count' => $schedule->metadata['failure_count'] ?? 0,
            'last_error' => $schedule->metadata['last_error'] ?? null
        ];
    }
}
