<?php

namespace App\Core\Monitoring\Request;

class RequestMonitor {
    private RequestTracker $tracker;
    private PerformanceAnalyzer $analyzer;
    private ErrorDetector $errorDetector;
    private MetricsCollector $metricsCollector;
    private AlertDispatcher $alertDispatcher;

    public function __construct(
        RequestTracker $tracker,
        PerformanceAnalyzer $analyzer,
        ErrorDetector $errorDetector,
        MetricsCollector $metricsCollector,
        AlertDispatcher $alertDispatcher
    ) {
        $this->tracker = $tracker;
        $this->analyzer = $analyzer;
        $this->errorDetector = $errorDetector;
        $this->metricsCollector = $metricsCollector;
        $this->alertDispatcher = $alertDispatcher;
    }

    public function handleRequest(Request $request): RequestReport 
    {
        $tracking = $this->tracker->track($request);
        $performance = $this->analyzer->analyze($request);
        $errors = $this->errorDetector->detect($request);

        $metrics = $this->metricsCollector->collect($request);

        if ($this->shouldAlert($performance, $errors)) {
            $this->alertDispatcher->dispatch(
                new RequestAlert($request, $performance, $errors)
            );
        }

        return new RequestReport($tracking, $performance, $errors, $metrics);
    }

    private function shouldAlert(PerformanceData $performance, array $errors): bool 
    {
        return $performance->hasIssues() || !empty($errors);
    }
}

class RequestTracker {
    private array $trackedRequests = [];
    private PathAnalyzer $pathAnalyzer;
    private UserTracker $userTracker;

    public function track(Request $request): RequestTracking 
    {
        $tracking = new RequestTracking(
            $request,
            $this->pathAnalyzer->analyze($request->getPathInfo()),
            $this->userTracker->track($request)
        );

        $this->trackedRequests[] = $tracking;

        return $tracking;
    }

    public function getTrackedRequests(): array 
    {
        return $this->trackedRequests;
    }
}

class PerformanceAnalyzer {
    private array $thresholds;
    private array $metrics;

    public function analyze(Request $request): PerformanceData 
    {
        $startTime = $request->getStartTime();
        $endTime = microtime(true);
        $duration = $endTime - $startTime;

        $metrics = [
            'duration' => $duration,
            'memory' => memory_get_peak_usage(true),
            'db_queries' => $this->countDatabaseQueries(),
            'cache_hits' => $this->countCacheHits(),
            'response_size' => $this->calculateResponseSize()
        ];

        $issues = $this->detectIssues($metrics);

        return new PerformanceData($metrics, $issues);
    }

    private function detectIssues(array $metrics): array 
    {
        $issues = [];

        foreach ($this->thresholds as $metric => $threshold) {
            if (isset($metrics[$metric]) && $metrics[$metric] > $threshold) {
                $issues[] = new PerformanceIssue(
                    $metric,
                    $metrics[$metric],
                    $threshold
                );
            }
        }

        return $issues;
    }
}

class ErrorDetector {
    private array $patterns;
    private LogReader $logReader;
    private ErrorClassifier $classifier;

    public function detect(Request $request): array 
    {
        $logs = $this->logReader->getRequestLogs($request);
        $errors = [];

        foreach ($logs as $log) {
            foreach ($this->patterns as $pattern) {
                if ($pattern->matches($log)) {
                    $errors[] = new Error(
                        $log,
                        $this->classifier->classify($log)
                    );
                }
            }
        }

        return $errors;
    }
}

class RequestReport {
    private RequestTracking $tracking;
    private PerformanceData $performance;
    private array $errors;
    private array $metrics;
    private float $timestamp;

    public function __construct(
        RequestTracking $tracking,
        PerformanceData $performance,
        array $errors,
        array $metrics
    ) {
        $this->tracking = $tracking;
        $this->performance = $performance;
        $this->errors = $errors;
        $this->metrics = $metrics;
        $this->timestamp = microtime(true);
    }

    public function hasIssues(): bool 
    {
        return $this->performance->hasIssues() || !empty($this->errors);
    }

    public function toArray(): array 
    {
        return [
            'tracking' => $this->tracking->toArray(),
            'performance' => $this->performance->toArray(),
            'errors' => array_map(fn($e) => $e->toArray(), $this->errors),
            'metrics' => $this->metrics,
            'timestamp' => $this->timestamp,
            'has_issues' => $this->hasIssues()
        ];
    }
}

class RequestTracking {
    private Request $request;
    private PathAnalysis $pathAnalysis;
    private UserTracking $userTracking;
    private float $startTime;

    public function __construct(
        Request $request,
        PathAnalysis $pathAnalysis,
        UserTracking $userTracking
    ) {
        $this->request = $request;
        $this->pathAnalysis = $pathAnalysis;
        $this->userTracking = $userTracking;
        $this->startTime = microtime(true);
    }

    public function getDuration(): float 
    {
        return microtime(true) - $this->startTime;
    }

    public function toArray(): array 
    {
        return [
            'method' => $this->request->getMethod(),
            'path' => $this->request->getPathInfo(),
            'path_analysis' => $this->pathAnalysis->toArray(),
            'user_tracking' => $this->userTracking->toArray(),
            'duration' => $this->getDuration(),
            'start_time' => $this->startTime
        ];
    }
}

class PerformanceData {
    private array $metrics;
    private array $issues;
    private float $timestamp;

    public function __construct(array $metrics, array $issues) 
    {
        $this->metrics = $metrics;
        $this->issues = $issues;
        $this->timestamp = microtime(true);
    }

    public function hasIssues(): bool 
    {
        return !empty($this->issues);
    }

    public function getMetrics(): array 
    {
        return $this->metrics;
    }

    public function getIssues(): array 
    {
        return $this->issues;
    }

    public function toArray(): array 
    {
        return [
            'metrics' => $this->metrics,
            'issues' => array_map(fn($i) => $i->toArray(), $this->issues),
            'timestamp' => $this->timestamp
        ];
    }
}

class Error {
    private LogEntry $log;
    private string $classification;
    private float $timestamp;

    public function __construct(LogEntry $log, string $classification) 
    {
        $this->log = $log;
        $this->classification = $classification;
        $this->timestamp = microtime(true);
    }

    public function toArray(): array 
    {
        return [
            'message' => $this->log->getMessage(),
            'level' => $this->log->getLevel(),
            'classification' => $this->classification,
            'context' => $this->log->getContext(),
            'timestamp' => $this->timestamp
        ];
    }
}

class RequestAlert {
    private Request $request;
    private PerformanceData $performance;
    private array $errors;
    private float $timestamp;

    public function __construct(
        Request $request,
        PerformanceData $performance,
        array $errors
    ) {
        $this->request = $request;
        $this->performance = $performance;
        $this->errors = $errors;
        $this->timestamp = microtime(true);
    }

    public function getSeverity(): string 
    {
        if (!empty($this->errors)) {
            return 'critical';
        }
        if ($this->performance->hasIssues()) {
            return 'warning';
        }
        return 'info';
    }

    public function toArray(): array 
    {
        return [
            'severity' => $this->getSeverity(),
            'request' => [
                'method' => $this->request->getMethod(),
                'path' => $this->request->getPathInfo()
            ],
            'performance' => $this->performance->toArray(),
            'errors' => array_map(fn($e) => $e->toArray(), $this->errors),
            'timestamp' => $this->timestamp
        ];
    }
}
