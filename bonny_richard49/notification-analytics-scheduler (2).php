<?php

namespace App\Core\Notification\Analytics\Scheduler;

use Illuminate\Support\Facades\Cache;
use App\Core\Notification\Analytics\NotificationAnalytics;
use App\Core\Notification\Analytics\Events\ScheduledAnalyticsCompleted;

class AnalyticsScheduler
{
    private NotificationAnalytics $analytics;
    private array $schedules;
    private array $locks = [];

    public function __construct(NotificationAnalytics $analytics)
    {
        $this->analytics = $analytics;
        $this->schedules = config('analytics.schedules');
    }

    public function executeScheduledAnalytics(): void
    {
        foreach ($this->schedules as $job => $schedule) {
            if ($this->shouldExecute($job, $schedule)) {
                $this->executeJob($job, $schedule);
            }
        }
    }

    public function scheduleCustomAnalytics(string $name, array $config): void
    {
        $schedule = [
            'interval' => $config['interval'] ?? 3600,
            'retention' => $config['retention'] ?? 86400,
            'priority' => $config['priority'] ?? 'low',
            'callback' => $config['callback'] ?? null
        ];

        $this->schedules[$name] = $schedule;
        Cache::put("analytics_schedule:{$name}", $schedule, $schedule['retention']);
    }

    private function shouldExecute(string $job, array $schedule): bool
    {
        $lockKey = "analytics_lock:{$job}";
        $lastRunKey = "analytics_last_run:{$job}";

        if (Cache::has($lockKey)) {
            return false;
        }

        $lastRun = Cache::get($lastRunKey, 0);
        return (time() - $lastRun) >= $schedule['interval'];
    }

    private function executeJob(string $job, array $schedule): void
    {
        $lockKey = "analytics_lock:{$job}";
        $lastRunKey = "analytics_last_run:{$job}";

        try {
            Cache::put($lockKey, true, 300);
            $this->locks[$job] = true;

            $result = $this->runAnalytics($job, $schedule);

            Cache::put($lastRunKey, time(), $schedule['retention']);
            $this->storeResult($job, $result);

            event(new ScheduledAnalyticsCompleted($job, $result));

        } catch (\Exception $e) {
            $this->handleJobError($job, $e);
        } finally {
            $this->releaseLock($job);
        }
    }

    private function runAnalytics(string $job, array $schedule): array
    {
        switch ($job) {
            case 'performance_metrics':
                return $this->analytics->analyzePerformance();

            case 'user_segments':
                return $this->analytics->analyzeUserSegments();

            case 'channel_effectiveness':
                return $this->analytics->analyzeChannelEffectiveness();

            default:
                if (isset($schedule['callback']) && is_callable($schedule['callback'])) {
                    return call_user_func($schedule['callback']);
                }
                
                throw new \InvalidArgumentException("Invalid analytics job: {$job}");
        }
    }

    private function storeResult(string $job, array $result): void
    {
        $key = "analytics_result:{$job}";
        $retention = $this->schedules[$job]['retention'] ?? 86400;

        Cache::put($key, [
            'data' => $result,
            'generated_at' => time()
        ], $retention);
    }

    private function handleJobError(string $job, \Exception $e): void
    {
        $key = "analytics_error:{$job}";
        
        Cache::put($key, [
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'time' => time()
        ], 86400);

        \Log::error("Analytics job failed: {$job}", [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    private function releaseLock(string $job): void
    {
        $lockKey = "analytics_lock:{$job}";
        Cache::forget($lockKey);
        unset($this->locks[$job]);
    }
}
