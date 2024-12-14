<?php

namespace App\Core\Scheduler\Models;

class Schedule extends Model
{
    protected $fillable = [
        'name',
        'command',
        'expression',
        'timezone',
        'without_overlapping',
        'on_one_server',
        'status',
        'metadata'
    ];

    protected $casts = [
        'metadata' => 'array',
        'without_overlapping' => 'boolean',
        'on_one_server' => 'boolean',
        'last_run_at' => 'datetime',
        'next_run_at' => 'datetime'
    ];
}

class ScheduleHistory extends Model
{
    protected $fillable = [
        'schedule_id',
        'status',
        'output',
        'started_at',
        'finished_at'
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime'
    ];
}

namespace App\Core\Scheduler\Services;

class SchedulerManager
{
    private TaskRunner $runner;
    private ScheduleRepository $repository;
    private LockManager $lockManager;

    public function run(): void
    {
        $schedules = $this->repository->getDue();

        foreach ($schedules as $schedule) {
            if ($this->shouldRun($schedule)) {
                $this->runSchedule($schedule);
            }
        }
    }

    private function shouldRun(Schedule $schedule): bool
    {
        if ($schedule->without_overlapping && !$this->lockManager->acquire($schedule)) {
            return false;
        }

        if ($schedule->on_one_server && !$this->lockManager->acquireOnServer($schedule)) {
            return false;
        }

        return true;
    }

    private function runSchedule(Schedule $schedule): void
    {
        try {
            $history = $this->createHistory($schedule);
            $output = $this->runner->run($schedule);
            $this->completeHistory($history, $output);
        } finally {
            $this->lockManager->release($schedule);
        }
    }
}

class TaskRunner
{
    public function run(Schedule $schedule): string
    {
        $process = Process::fromShellCommandline($schedule->command);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        return $process->getOutput();
    }
}

class LockManager
{
    private Cache $cache;

    public function acquire(Schedule $schedule): bool
    {
        return $this->cache->add(
            $this->getLockKey($schedule),
            true,
            $this->getLockTimeout($schedule)
        );
    }

    public function acquireOnServer(Schedule $schedule): bool
    {
        return Cache::store('redis')->add(
            $this->getServerLockKey($schedule),
            true,
            $this->getLockTimeout($schedule)
        );
    }

    public function release(Schedule $schedule): void
    {
        $this->cache->forget($this->getLockKey($schedule));
        Cache::store('redis')->forget($this->getServerLockKey($schedule));
    }

    private function getLockKey(Schedule $schedule): string
    {
        return "schedule:{$schedule->id}:lock";
    }

    private function getServerLockKey(Schedule $schedule): string
    {
        return "schedule:{$schedule->id}:server_lock";
    }
}

class ExpressionParser
{
    public function isDue(string $expression, string $timezone = null): bool
    {
        $cron = CronExpression::factory($expression);
        
        if ($timezone) {
            $cron->setTimezone(new \DateTimeZone($timezone));
        }

        return $cron->isDue();
    }

    public function getNextRunDate(string $expression, string $timezone = null): Carbon
    {
        $cron = CronExpression::factory($expression);
        
        if ($timezone) {
            $cron->setTimezone(new \DateTimeZone($timezone));
        }

        return Carbon::instance($cron->getNextRunDate());
    }
}

namespace App\Core\Scheduler\Http\Controllers;

class ScheduleController extends Controller
{
    private SchedulerManager $manager;
    private ScheduleRepository $repository;

    public function store(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'name' => 'required|string',
                'command' => 'required|string',
                'expression' => 'required|string',
                'timezone' => 'nullable|string'
            ]);

            $schedule = $this->repository->create($request->all());
            return response()->json($schedule, 201);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function history(int $id): JsonResponse
    {
        $history = $this->repository->getHistory($id);
        return response()->json($history);
    }

    public function toggle(int $id): JsonResponse
    {
        try {
            $schedule = $this->repository->find($id);
            $schedule->update(['status' => $schedule->status === 'active' ? 'inactive' : 'active']);
            return response()->json($schedule);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }
}

namespace App\Core\Scheduler\Console;

class RunSchedulerCommand extends Command
{
    protected $signature = 'scheduler:run';

    public function handle(SchedulerManager $manager): void
    {
        $manager->run();
    }
}

class ListSchedulesCommand extends Command
{
    protected $signature = 'scheduler:list';

    public function handle(ScheduleRepository $repository): void
    {
        $schedules = $repository->all();
        
        $rows = $schedules->map(function ($schedule) {
            return [
                $schedule->id,
                $schedule->name,
                $schedule->expression,
                $schedule->status,
                $schedule->last_run_at?->format('Y-m-d H:i:s'),
                $schedule->next_run_at?->format('Y-m-d H:i:s')
            ];
        });

        $this->table(
            ['ID', 'Name', 'Expression', 'Status', 'Last Run', 'Next Run'],
            $rows
        );
    }
}
