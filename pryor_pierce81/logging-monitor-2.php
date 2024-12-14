<?php

namespace App\Core\Monitoring\Logging;

class LoggingMonitor 
{
    private LogCollector $collector;
    private LogAnalyzer $analyzer;
    private StorageMonitor $storageMonitor;
    private AlertManager $alertManager;
    private ErrorAggregator $errorAggregator;

    public function monitor(): LoggingStatus
    {
        $logs = $this->collector->collect();
        $analysis = $this->analyzer->analyze($logs);
        $storage = $this->storageMonitor->check();
        $errors = $this->errorAggregator->aggregate($logs);

        $status = new LoggingStatus($logs, $analysis, $storage, $errors);

        if ($status->hasIssues()) {
            $this->alertManager->notify(new LoggingAlert($status));
        }

        return $status;
    }
}

class LogCollector
{
    private array $sources;
    private LogParser $parser;
    private LogFilter $filter;

    public function collect(): LogCollection
    {
        $logs = [];
        foreach ($this->sources as $source) {
            try {
                $rawLogs = $source->fetch();
                $parsedLogs = $this->parser->parse($rawLogs);
                $filteredLogs = $this->filter->filter($parsedLogs);
                $logs[$source->getName()] = $filteredLogs;
            } catch (CollectionException $e) {
                // Handle collection error
            }
        }

        return new LogCollection($logs);
    }
}

class LogAnalyzer
{
    private PatternDetector $patternDetector;
    private FrequencyAnalyzer $frequencyAnalyzer;
    private SeverityAnalyzer $severityAnalyzer;
    private TrendAnalyzer $trendAnalyzer;

    public function analyze(LogCollection $logs): LogAnalysis
    {
        $patterns = $this->patternDetector->detect($logs);
        $frequencies = $this->frequencyAnalyzer->analyze($logs);
        $severities = $this->severityAnalyzer->analyze($logs);
        $trends = $this->trendAnalyzer->analyze($logs);

        return new LogAnalysis($patterns, $frequencies, $severities, $trends);
    }
}

class StorageMonitor
{
    private StorageUsage $usage;
    private RetentionChecker $retention;
    private RotationChecker $rotation;

    public function check(): StorageStatus
    {
        return new StorageStatus(
            $this->usage->check(),
            $this->retention->check(),
            $this->rotation->check()
        );
    }
}

class ErrorAggregator
{
    private ErrorClassifier $classifier;
    private ErrorGrouper $grouper;
    private ImpactAnalyzer $impactAnalyzer;

    public function aggregate(LogCollection $logs): ErrorSummary
    {
        $classified = $this->classifier->classify($logs);
        $grouped = $this->grouper->group($classified);
        $impact = $this->impactAnalyzer->analyze($grouped);

        return new ErrorSummary($grouped, $impact);
    }
}

class LoggingStatus
{
    private LogCollection $logs;
    private LogAnalysis $analysis;
    private StorageStatus $storage;
    private ErrorSummary $errors;
    private float $timestamp;

    public function __construct(
        LogCollection $logs,
        LogAnalysis $analysis,
        StorageStatus $storage,
        ErrorSummary $errors
    ) {
        $this->logs = $logs;
        $this->analysis = $analysis;
        $this->storage = $storage;
        $this->errors = $errors;
        $this->timestamp = microtime(true);
    }

    public function hasIssues(): bool
    {
        return $this->analysis->hasIssues() ||
               $this->storage->hasIssues() ||
               $this->errors->hasCriticalErrors();
    }

    public function getLogs(): LogCollection
    {
        return $this->logs;
    }

    public function getAnalysis(): LogAnalysis
    {
        return $this->analysis;
    }

    public function getStorage(): StorageStatus
    {
        return $this->storage;
    }

    public function getErrors(): ErrorSummary
    {
        return $this->errors;
    }

    public function getTimestamp(): float
    {
        return $this->timestamp;
    }
}

class LogCollection
{
    private array $logs;
    private int $count;
    private float $startTime;
    private float $endTime;

    public function __construct(array $logs)
    {
        $this->logs = $logs;
        $this->count = $this->calculateCount();
        $this->startTime = $this->findStartTime();
        $this->endTime = $this->findEndTime();
    }

    public function getLogs(): array
    {
        return $this->logs;
    }

    public function getCount(): int
    {
        return $this->count;
    }

    public function getTimeRange(): array
    {
        return [
            'start' => $this->startTime,
            'end' => $this->endTime
        ];
    }

    private function calculateCount(): int
    {
        return count($this->logs);
    }

    private function findStartTime(): float
    {
        return min(array_column($this->logs, 'timestamp'));
    }

    private function findEndTime(): float
    {
        return max(array_column($this->logs, 'timestamp'));
    }
}

class LogAnalysis
{
    private array $patterns;
    private array $frequencies;
    private array $severities;
    private array $trends;

    public function __construct(
        array $patterns,
        array $frequencies,
        array $severities,
        array $trends
    ) {
        $this->patterns = $patterns;
        $this->frequencies = $frequencies;
        $this->severities = $severities;
        $this->trends = $trends;
    }

    public function hasIssues(): bool
    {
        return !empty($this->patterns) || $this->hasSeverityIssues();
    }

    private function hasSeverityIssues(): bool
    {
        return isset($this->severities['critical']) && $this->severities['critical'] > 0;
    }
}

class StorageStatus
{
    private array $usage;
    private array $retention;
    private array $rotation;
    private float $timestamp;

    public function __construct(array $usage, array $retention, array $rotation)
    {
        $this->usage = $usage;
        $this->retention = $retention;
        $this->rotation = $rotation;
        $this->timestamp = microtime(true);
    }

    public function hasIssues(): bool
    {
        return $this->usage['percentage'] > 90 ||
               !$this->retention['compliant'] ||
               !$this->rotation['working'];
    }
}

class ErrorSummary
{
    private array $groups;
    private array $impact;
    private int $totalErrors;
    private int $criticalErrors;

    public function __construct(array $groups, array $impact)
    {
        $this->groups = $groups;
        $this->impact = $impact;
        $this->totalErrors = $this->calculateTotal();
        $this->criticalErrors = $this->calculateCritical();
    }

    public function hasCriticalErrors(): bool
    {
        return $this->criticalErrors > 0;
    }

    private function calculateTotal(): int
    {
        return array_sum(array_column($this->groups, 'count'));
    }

    private function calculateCritical(): int
    {
        return array_sum(array_column(
            array_filter($this->groups, fn($g) => $g['severity'] === 'critical'),
            'count'
        ));
    }
}
