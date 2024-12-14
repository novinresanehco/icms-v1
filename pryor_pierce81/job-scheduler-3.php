<?php

namespace App\Core\Monitoring\Jobs\Scheduling;

class JobScheduler
{
    private TaskQueue $queue;
    private TimeManager $timeManager;
    private ResourceMonitor $resourceMonitor;
    private PriorityManager $priorityManager;
    private JobValidator $validator;

    public function schedule(SchedulableJob $job, ScheduleConfig $config): ScheduleResult
    {
        if (!$this->validator->validate($job, $config)) {
            throw new InvalidJobException("Job validation failed");
        }

        $priority = $this->priorityManager->calculatePriority($job, $config);
        $resources = $this->resourceMonitor->checkAvailability($job->getRequirements());
        
        if (!$resources->isAvailable()) {
            return new ScheduleResult(false, "Insufficient resources");
        }

        $schedule = new JobSchedule([
            'job' => $job,
            'config' => $config,
            'priority' => $priority,
            'execution_time' => $this->timeManager->calculateExecutionTime($config),
            'resources' => $resources
        ]);

        $this->queue->enqueue($schedule);

        return new ScheduleResult(true, "Job scheduled successfully", $schedule);
    }

    public function reschedule(string $jobId, ScheduleConfig $newConfig): ScheduleResult
    {
        $existingSchedule = $this->queue->findSchedule($jobId);
        if (!$existingSchedule) {
            throw new ScheduleNotFoundException("Schedule not found for job: $jobId");
        }

        $this->queue->remove($jobId);
        return $this->schedule($existingSchedule->getJob(), $newConfig);
    }

    public function cancel(string $jobId): void
    {
        $schedule = $this->queue->findSchedule($jobId);
        if (!$schedule) {
            throw new ScheduleNotFoundException("Schedule not found for job: $jobId");
        }

        $this->queue->remove($jobId);
        $this->resourceMonitor->releaseResources($schedule->getResources());
    }
}

class TaskQueue
{
    private PriorityQueue $queue;
    private array $schedules = [];

    public function enqueue(JobSchedule $schedule): void
    {
        $this->queue->insert($schedule, $schedule->getPriority());
        $this->schedules[$schedule->getJobId()] = $schedule;
    }

    public function dequeue(): ?JobSchedule
    {
        if ($this->queue->isEmpty()) {
            return null;
        }

        $schedule = $this->queue->extract();
        unset($this->schedules[$schedule->getJobId()]);
        return $schedule;
    }

    public function remove(string $jobId): void
    {
        if (!isset($this->schedules[$jobId])) {
            return;
        }

        $schedule = $this->schedules[$jobId];
        $this->queue->remove($schedule);
        unset($this->schedules[$jobId]);
    }

    public function findSchedule(string $jobId): ?JobSchedule
    {
        return $this->schedules[$jobId] ?? null;
    }
}

class TimeManager
{
    private TimeZoneManager $tzManager;
    private array $holidays;
    private array $blackoutPeriods;

    public function calculateExecutionTime(ScheduleConfig $config): \DateTime
    {
        $baseTime = $config->getStartTime() ?? new \DateTime();
        
        if ($config->hasInterval()) {
            return $this->calculateNextInterval($baseTime, $config->getInterval());
        }

        if ($config->hasCronExpression()) {
            return $this->calculateNextCronTime($baseTime, $config->getCronExpression());
        }

        return $this->adjustForConstraints($baseTime, $config);
    }

    private function calculateNextInterval(\DateTime $baseTime, string $interval): \DateTime
    {
        $next = clone $baseTime;
        $next->modify("+$interval");
        
        while ($this->isInBlackoutPeriod($next) || $this->isHoliday($next)) {
            $next->modify("+$interval");
        }

        return $next;
    }

    private function calculateNextCronTime(\DateTime $baseTime, string $expression): \DateTime
    {
        $cron = new CronExpression($expression);
        $next = $cron->getNextRunDate($baseTime);

        while ($this->isInBlackoutPeriod($next) || $this->isHoliday($next)) {
            $next = $cron->getNextRunDate($next);
        }

        return $next;
    }

    private function adjustForConstraints(\DateTime $time, ScheduleConfig $config): \DateTime
    {
        $adjusted = clone $time;

        if ($config->hasTimeWindow()) {
            $adjusted = $this->adjustForTimeWindow($adjusted, $config->getTimeWindow());
        }

        if ($this->isInBlackoutPeriod($adjusted) || $this->isHoliday($adjusted)) {
            $adjusted = $this->findNextAvailableTime($adjusted);
        }

        return $adjusted;
    }

    private function isInBlackoutPeriod(\DateTime $time): bool
    {
        foreach ($this->blackoutPeriods as $period) {
            if ($time >= $period['start'] && $time <= $period['end']) {
                return true;
            }
        }
        return false;
    }

    private function isHoliday(\DateTime $time): bool
    {
        $date = $time->format('Y-m-d');
        return isset($this->holidays[$date]);
    }
}

class ResourceMonitor
{
    private SystemMonitor $systemMonitor;
    private ResourceAllocation $allocation;
    private ResourceLimits $limits;

    public function checkAvailability(ResourceRequirements $requirements): ResourceAvailability
    {
        $currentUsage = $this->systemMonitor->getCurrentUsage();
        $projected = $this->calculateProjectedUsage($currentUsage, $requirements);

        if ($this->exceedsLimits($projected)) {
            return new ResourceAvailability(false, $this->getExceededResources($projected));
        }

        return new ResourceAvailability(true, [
            'cpu' => $projected->getCpuAvailability(),
            'memory' => $projected->getMemoryAvailability(),
            'disk' => $projected->getDiskAvailability()
        ]);
    }

    public function releaseResources(ResourceAllocation $resources): void
    {
        $this->allocation->release($resources);
    }

    private function calculateProjectedUsage(SystemUsage $current, ResourceRequirements $requirements): ProjectedUsage
    {
        return new ProjectedUsage(
            $current->getCpuUsage() + $requirements->getCpuRequirement(),
            $current->getMemoryUsage() + $requirements->getMemoryRequirement(),
            $current->getDiskUsage() + $requirements->getDiskRequirement()
        );
    }

    private function exceedsLimits(ProjectedUsage $usage): bool
    {
        return $usage->getCpuUsage() > $this->limits->getCpuLimit() ||
               $usage->getMemoryUsage() > $this->limits->getMemoryLimit() ||
               $usage->getDiskUsage() > $this->limits->getDiskLimit();
    }
}

class PriorityManager
{
    private array $factors;
    private array $weights;

    public function calculatePriority(SchedulableJob $job, ScheduleConfig $config): int
    {
        $score = 0;

        foreach ($this->factors as $factor => $calculator) {
            $factorScore = $calculator->calculate($job, $config);
            $weight = $this->weights[$factor] ?? 1;
            $score += $factorScore * $weight;
        }

        return max(1, min(100, (int) $score));
    }

    public function addFactor(string $name, PriorityFactor $calculator, float $weight = 1.0): void
    {
        $this->factors[$name] = $calculator;
        $this->weights[$name] = $weight;
    }
}

class ScheduleConfig
{
    private ?\DateTime $startTime = null;
    private ?string $interval = null;
    private ?string $cronExpression = null;
    private ?TimeWindow $timeWindow = null;
    private array $constraints = [];

    public function __construct(array $config = [])
    {
        if (isset($config['start_time'])) {
            $this->startTime = new \DateTime($config['start_time']);
        }
        $this->interval = $config['interval'] ?? null;
        $this->cronExpression = $config['cron'] ?? null;
        if (isset($config['time_window'])) {
            $this->timeWindow = new TimeWindow($config['time_window']);
        }
        $this->constraints = $config['constraints'] ?? [];
    }

    public function getStartTime(): ?\DateTime
    {
        return $this->startTime;
    }

    public function hasInterval(): bool
    {
        return $this->interval !== null;
    }

    public function getInterval(): ?string
    {
        return $this->interval;
    }

    public function hasCronExpression(): bool
    {
        return $this->cronExpression !== null;
    }

    public function getCronExpression(): ?string
    {
        return $this->cronExpression;
    }

    public function hasTimeWindow(): bool
    {
        return $this->timeWindow !== null;
    }

    public function getTimeWindow(): ?TimeWindow
    {
        return $this->timeWindow;
    }

    public function getConstraints(): array
    {
        return $this->constraints;
    }
}
