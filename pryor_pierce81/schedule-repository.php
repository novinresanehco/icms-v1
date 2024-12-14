<?php

namespace App\Core\Repository;

use App\Models\Schedule;
use App\Core\Events\ScheduleEvents;
use App\Core\Exceptions\ScheduleRepositoryException;

class ScheduleRepository extends BaseRepository
{
    protected function getModelClass(): string
    {
        return Schedule::class;
    }

    /**
     * Create scheduled task
     */
    public function createSchedule(string $type, array $data, string $expression): Schedule
    {
        try {
            // Validate cron expression
            if (!$this->isValidCronExpression($expression)) {
                throw new ScheduleRepositoryException("Invalid cron expression: {$expression}");
            }

            $schedule = $this->create([
                'type' => $type,
                'data' => $data,
                'cron_expression' => $expression,
                'status' => 'active',
                'created_by' => auth()->id(),
                'next_run_at' => $this->getNextRunDate($expression)
            ]);

            event(new ScheduleEvents\ScheduleCreated($schedule));
            return $schedule;

        } catch (\Exception $e) {
            throw new ScheduleRepositoryException(
                "Failed to create schedule: {$e->getMessage()}"
            );
        }
    }

    /**
     * Get due schedules
     */
    public function getDueSchedules(): Collection
    {
        return $this->model
            ->where('status', 'active')
            ->where('next_run_at', '<=', now())
            ->get();
    }

    /**
     * Update next run time
     */
    public function updateNextRun(int $scheduleId): void
    {
        try {
            $schedule = $this->find($scheduleId);
            if (!$schedule) {
                throw new ScheduleRepositoryException("Schedule not found with ID: {$scheduleId}");
            }

            $schedule->update([
                'last_run_at' => now(),
                'next_run_at' => $this->getNextRunDate($schedule->cron_expression),
                'runs_count' => DB::raw('runs_count + 1')
            ]);

            $this->clearCache();

        } catch (\Exception $e) {
            throw new ScheduleRepositoryException(
                "Failed to update next run time: {$e->getMessage()}"
            );
        }
    }

    /**
     * Record schedule execution
     */
    public function recordExecution(int $scheduleId, string $status, array $result = []): void
    {
        try {
            DB::table('schedule_executions')->insert([
                'schedule_id' => $scheduleId,
                'status' => $status,
                'result' => json_encode($result),
                'executed_at' => now()
            ]);
        } catch (\Exception $e) {
            \Log::error("Failed to record schedule execution: {$e->getMessage()}", [
                'schedule_id' => $scheduleId,
                'status' => $status
            ]);
        }
    }

    /**
     * Get schedule execution history
     */
    public function getExecutionHistory(int $scheduleId, array $options = []): Collection
    {
        $query = DB::table('schedule_executions')
            ->where('schedule_id', $scheduleId);

        if (isset($options['status'])) {
            $query->where('status', $options['status']);
        }

        if (isset($options['from'])) {
            $query->where('executed_at', '>=', $options['from']);
        }

        if (isset($options['limit'])) {
            $query->limit($options['limit']);
        }

        return $query->orderByDesc('executed_at')->get();
    }

    /**
     * Check if cron expression is valid
     */
    protected function isValidCronExpression(string $expression): bool
    {
        try {
            new CronExpression($expression);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get next run date from cron expression
     */
    protected function getNextRunDate(string $expression): Carbon
    {
        $cron = new CronExpression($expression);
        return Carbon::instance($cron->getNextRunDate());
    }
}
