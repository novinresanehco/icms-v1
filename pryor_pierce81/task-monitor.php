<?php

namespace App\Core\Monitoring\Task;

class TaskMonitor
{
    private TaskRegistry $registry;
    private ExecutionTracker $tracker;
    private PerformanceAnalyzer $analyzer;
    private ResourceMonitor $resourceMonitor;
    private AlertManager $alertManager;

    public function monitor(): TaskStatus
    {
        $tasks = [];
        $executionStats = $this->tracker->getStats();
        $performance = $this->analyzer->analyze($executionStats);
        $resources = $this->resourceMonitor->check();

        foreach ($this->registry->getTasks() as $task) {
            $taskStatus = $this->monitorTask($task);
            if ($taskStatus->hasIssues()) {
                $this->alertManager->notify(new TaskAlert($taskStatus));
            }
            $tasks[$task->getId()] = $taskStatus;
        }

        return new TaskStatus($tasks, $executionStats, $performance, $resources);
    }

    private function monitorTask(Task $task): TaskInstanceStatus
    {
        return new TaskInstanceStatus(
            $task,
            $this->tracker->getTaskStats($task),
            $this->analyzer->analyzeTask($task),
            $this->resourceMonitor->checkTask($task)
        );
    }
}

class ExecutionTracker
{
    private ExecutionStore $store;
    private StatsCalculator $calculator;
    private TimeWindow $window;

    public function getStats(): ExecutionStats
    {
        $executions = $this->store->getExecutions($this->window);
        return $this->calculator->calculate($executions);
    }

    public function getTaskStats(Task $task): TaskStats
    {
        $executions = $this->store->getTaskExecutions($task, $this->window);
        return $this->calculator->calculateTaskStats($executions);
    }
}

class PerformanceAnalyzer
{
    private ThresholdManager $thresholds;
    private TrendAnalyzer $trends;
    private PatternDetector $patterns;

    public function analyze(ExecutionStats $stats): PerformanceAnalysis
    {
        return new PerformanceAnalysis(
            $this->thresholds->check($stats),
            $this->trends->analyze($stats),
            $this->patterns->detect($stats)
        );
    }

    public function analyzeTask(Task $task): TaskPerformance
    {
        return new TaskPerformance(
            $this->thresholds->checkTask($task),
            $this->trends->analyzeTask($task),
            $this->patterns->detectTask($task)
        );
    }
}

class ResourceMonitor
{
    private ResourceTracker $tracker;
    private ResourceCalculator $calculator;
    private LimitChecker $limitChecker;

    public function check(): ResourceStatus
    {
        $usage = $this->tracker->getCurrentUsage();
        $metrics = $this->calculator->calculate($usage);
        $limits = $this->limitChecker->check($metrics);

        return new ResourceStatus($metrics, $limits);
    }

    public function checkTask(Task $task): TaskResources
    {
        $usage = $this->tracker->getTaskUsage($task);
        $metrics = $this->calculator->calculateTaskMetrics($usage);
        $limits = $this->limitChecker->checkTask($metrics);

        return new TaskResources($metrics, $limits);
    }
}

class TaskStatus
{
    private array $tasks;
    private ExecutionStats $executionStats;
    private PerformanceAnalysis $performance;
    private ResourceStatus $resources;
    private float $timestamp;

    public function __construct(
        array $tasks,
        ExecutionStats $executionStats,
        PerformanceAnalysis $performance,
        ResourceStatus $resources
    ) {
        $this->tasks = $tasks;
        $this->executionStats = $executionStats;
        $this->performance = $performance;
        $this->resources = $resources;
        $this->timestamp = microtime(true);
    }

    public function hasIssues(): bool
    {
        return $this->performance->hasIssues() ||
               $this->resources->hasIssues() ||
               $this->hasTaskIssues();
    }

    private function hasTaskIssues(): bool
    {
        foreach ($this->tasks as $task) {
            if ($task->hasIssues()) {
                return true;
            }
        }
        return false;
    }
}

class TaskInstanceStatus
{
    private Task $task;
    private TaskStats $stats;
    private TaskPerformance $performance;
    private TaskResources $resources;

    public function __construct(
        Task $task,
        TaskStats $stats,
        TaskPerformance $performance,
        TaskResources $resources
    ) {
        $this->task = $task;
        $this->stats = $stats;
        $this->performance = $performance;
        $this->resources = $resources;
    }

    public function hasIssues(): bool
    {
        return $this->performance->hasIssues() || $this->resources->hasIssues();
    }
}

class ExecutionStats
{
    private array $stats;
    private float $timestamp;

    public function __construct(array $stats)
    {
        $this->stats = $stats;
        $this->timestamp = microtime(true);
    }

    public function getStats(): array
    {
        return $this->stats;
    }

    public function getTimestamp(): float
    {
        return $this->timestamp;
    }
}

class TaskStats
{
    private array $stats;
    private float $averageExecutionTime;
    private float $successRate;
    private int $totalExecutions;

    public function __construct(array $stats)
    {
        $this->stats = $stats;
        $this->averageExecutionTime = $this->calculateAverageExecutionTime();
        $this->successRate = $this->calculateSuccessRate();
        $this->totalExecutions = $this->calculateTotalExecutions();
    }

    private function calculateAverageExecutionTime(): float
    {
        return array_sum($this->stats['execution_times']) / count($this->stats['execution_times']);
    }

    private function calculateSuccessRate(): float
    {
        $total = count($this->stats['executions']);
        $successful = count(array_filter($this->stats['executions'], fn($e) => $e['success']));
        return $total > 0 ? ($successful / $total) * 100 : 0;
    }

    private function calculateTotalExecutions(): int
    {
        return count($this->stats['executions']);
    }
}
