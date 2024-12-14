<?php

namespace App\Core\Monitoring\Application;

class ApplicationMonitor
{
    private HealthChecker $healthChecker;
    private MetricsCollector $metricsCollector;
    private StatusManager $statusManager;
    private AlertDispatcher $alertDispatcher;
    private PerformanceAnalyzer $performanceAnalyzer;

    public function monitor(): ApplicationStatus
    {
        $health = $this->healthChecker->check();
        $metrics = $this->metricsCollector->collect();
        $performance = $this->performanceAnalyzer->analyze($metrics);

        $status = new ApplicationStatus($health, $metrics, $performance);
        $this->statusManager->update($status);

        if ($status->hasIssues()) {
            $this->alertDispatcher->dispatch(new StatusAlert($status));
        }

        return $status;
    }
}

class HealthChecker
{
    private array $checks = [];
    private DependencyManager $dependencies;
    private ResourceMonitor $resources;

    public function check(): HealthStatus
    {
        $results = [
            'system' => $this->checkSystem(),
            'dependencies' => $this->checkDependencies(),
            'resources' => $this->checkResources()
        ];

        foreach ($this->checks as $check) {
            $results[$check->getName()] = $check->execute();
        }

        return new HealthStatus($results);
    }

    private function checkSystem(): array
    {
        return [
            'memory' => $this->checkMemoryUsage(),
            'disk' => $this->checkDiskSpace(),
            'load' => $this->checkSystemLoad()
        ];
    }

    private function checkDependencies(): array
    {
        return $this->dependencies->checkAll();
    }

    private function checkResources(): array
    {
        return $this->resources->checkAvailability();
    }
}

class MetricsCollector
{
    private array $collectors;
    private MetricRegistry $registry;
    private DataProcessor $processor;

    public function collect(): MetricsCollection
    {
        $metrics = new MetricsCollection();

        foreach ($this->collectors as $collector) {
            try {
                $data = $collector->collect();
                $processed = $this->processor->process($data);
                $metrics->add($collector->getName(), $processed);
            } catch (\Exception $e) {
                $metrics->addError($collector->getName(), $e);
            }
        }

        return $metrics;
    }
}

class StatusManager
{
    private StatusStorage $storage;
    private StatusHistory $history;
    private ChangeDetector $changeDetector;

    public function update(ApplicationStatus $status): void
    {
        $previous = $this->storage->getCurrent();
        $changes = $this->changeDetector->detectChanges($previous, $status);

        if (!empty($changes)) {
            $this->history->record($changes);
        }

        $this->storage->store($status);
    }
}

class PerformanceAnalyzer
{
    private array $analyzers;
    private ThresholdManager $thresholds;
    private TrendAnalyzer $trends;

    public function analyze(MetricsCollection $metrics): PerformanceReport
    {
        $results = [];

        foreach ($this->analyzers as $analyzer) {
            $results[$analyzer->getName()] = $analyzer->analyze($metrics);
        }

        $thresholdViolations = $this->checkThresholds($metrics);
        $trends = $this->trends->analyze($metrics);

        return new PerformanceReport($results, $thresholdViolations, $trends);
    }

    private function checkThresholds(MetricsCollection $metrics): array
    {
        return $this->thresholds->check($metrics);
    }
}

class ApplicationStatus
{
    private HealthStatus $health;
    private MetricsCollection $metrics;
    private PerformanceReport $performance;
    private float $timestamp;

    public function __construct(
        HealthStatus $health,
        MetricsCollection $metrics,
        PerformanceReport $performance
    ) {
        $this->health = $health;
        $this->metrics = $metrics;
        $this->performance = $performance;
        $this->timestamp = microtime(true);
    }

    public function hasIssues(): bool
    {
        return !$this->health->isHealthy() ||
               $this->performance->hasIssues() ||
               $this->metrics->hasErrors();
    }

    public function getHealth(): HealthStatus
    {
        return $this->health;
    }

    public function getMetrics(): MetricsCollection
    {
        return $this->metrics;
    }

    public function getPerformance(): PerformanceReport
    {
        return $this->performance;
    }

    public function getTimestamp(): float
    {
        return $this->timestamp;
    }
}

class HealthStatus
{
    private array $results;
    private bool $healthy;

    public function __construct(array $results)
    {
        $this->results = $results;
        $this->healthy = $this->calculateHealth();
    }

    public function isHealthy(): bool
    {
        return $this->healthy;
    }

    public function getResults(): array
    {
        return $this->results;
    }

    private function calculateHealth(): bool
    {
        foreach ($this->results as $result) {
            if (isset($result['status']) && $result['status'] === 'unhealthy') {
                return false;
            }
        }
        return true;
    }
}

class MetricsCollection
{
    private array $metrics = [];
    private array $errors = [];

    public function add(string $name, array $data): void
    {
        $this->metrics[$name] = $data;
    }

    public function addError(string $name, \Exception $error): void
    {
        $this->errors[$name] = [
            'message' => $error->getMessage(),
            'code' => $error->getCode(),
            'timestamp' => time()
        ];
    }

    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }

    public function getMetrics(): array
    {
        return $this->metrics;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}

class PerformanceReport
{
    private array $results;
    private array $thresholdViolations;
    private array $trends;

    public function __construct(array $results, array $thresholdViolations, array $trends)
    {
        $this->results = $results;
        $this->thresholdViolations = $thresholdViolations;
        $this->trends = $trends;
    }

    public function hasIssues(): bool
    {
        return !empty($this->thresholdViolations);
    }

    public function getResults(): array
    {
        return $this->results;
    }

    public function getViolations(): array
    {
        return $this->thresholdViolations;
    }

    public function getTrends(): array
    {
        return $this->trends;
    }
}

class StatusAlert
{
    private ApplicationStatus $status;
    private string $level;
    private array $details;

    public function __construct(ApplicationStatus $status)
    {
        $this->status = $status;
        $this->level = $this->determineLevel();
        $this->details = $this->gatherDetails();
    }

    private function determineLevel(): string
    {
        if (!$this->status->getHealth()->isHealthy()) {
            return 'critical';
        }

        if ($this->status->getPerformance()->hasIssues()) {
            return 'warning';
        }

        return 'info';
    }

    private function gatherDetails(): array
    {
        return [
            'health' => $this->status->getHealth()->getResults(),
            'performance' => $this->status->getPerformance()->getViolations(),
            'metrics' => $this->status->getMetrics()->getErrors()
        ];
    }
}