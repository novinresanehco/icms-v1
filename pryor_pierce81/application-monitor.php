<?php

namespace App\Core\Monitoring\Application;

class ApplicationMonitor {
    private ErrorTracker $errorTracker;
    private PerformanceTracker $performanceTracker;
    private RequestTracker $requestTracker;
    private SecurityMonitor $securityMonitor;
    private MetricsAggregator $metricsAggregator;

    public function __construct(
        ErrorTracker $errorTracker,
        PerformanceTracker $performanceTracker,
        RequestTracker $requestTracker,
        SecurityMonitor $securityMonitor,
        MetricsAggregator $metricsAggregator
    ) {
        $this->errorTracker = $errorTracker;
        $this->performanceTracker = $performanceTracker;
        $this->requestTracker = $requestTracker;
        $this->securityMonitor = $securityMonitor;
        $this->metricsAggregator = $metricsAggregator;
    }

    public function collectMetrics(): ApplicationMetrics 
    {
        $metrics = new ApplicationMetrics([
            'errors' => $this->errorTracker->getMetrics(),
            'performance' => $this->performanceTracker->getMetrics(),
            'requests' => $this->requestTracker->getMetrics(),
            'security' => $this->securityMonitor->getMetrics()
        ]);

        $this->metricsAggregator->aggregate($metrics);

        return $metrics;
    }
}

class ErrorTracker {
    private LoggerInterface $logger;
    private array $errorCounts = [];
    private array $lastErrors = [];
    private int $maxErrors = 100;

    public function track(\Throwable $error): void 
    {
        $type = get_class($error);
        
        $this->errorCounts[$type] = ($this->errorCounts[$type] ?? 0) + 1;
        
        $this->lastErrors[] = [
            'type' => $type,
            'message' => $error->getMessage(),
            'file' => $error->getFile(),
            'line' => $error->getLine(),
            'timestamp' => microtime(true)
        ];

        if (count($this->lastErrors) > $this->maxErrors) {
            array_shift($this->lastErrors);
        }

        $this->logger->error($error->getMessage(), [
            'exception' => $error,
            'count' => $this->errorCounts[$type]
        ]);
    }

    public function getMetrics(): array 
    {
        return [
            'counts' => $this->errorCounts,
            'recent' => $this->lastErrors,
            'total' => array_sum($this->errorCounts)
        ];
    }
}

class PerformanceTracker {
    private array $timings = [];
    private array $memory = [];
    private array $queries = [];

    public function trackTiming(string $operation, float $duration): void 
    {
        if (!isset($this->timings[$operation])) {
            $this->timings[$operation] = [
                'count' => 0,
                'total' => 0,
                'min' => $duration,
                'max' => $duration
            ];
        }

        $stats = &$this->timings[$operation];
        $stats['count']++;
        $stats['total'] += $duration;
        $stats['min'] = min($stats['min'], $duration);
        $stats['max'] = max($stats['max'], $duration);
    }

    public function trackMemory(string $operation, int $bytes): void 
    {
        if (!isset($this->memory[$operation])) {
            $this->memory[$operation] = [
                'count' => 0,
                'total' => 0,
                'peak' => 0
            ];
        }

        $stats = &$this->memory[$operation];
        $stats['count']++;
        $stats['total'] += $bytes;
        $stats['peak'] = max($stats['peak'], $bytes);
    }

    public function trackQuery(string $query, float $duration): void 
    {
        $hash = md5($query);
        
        if (!isset($this->queries[$hash])) {
            $this->queries[$hash] = [
                'query' => $query,
                'count' => 0,
                'total_time' => 0,
                'avg_time' => 0
            ];
        }

        $stats = &$this->queries[$hash];
        $stats['count']++;
        $stats['total_time'] += $duration;
        $stats['avg_time'] = $stats['total_time'] / $stats['count'];
    }

    public function getMetrics(): array 
    {
        return [
            'timings' => $this->timings,
            'memory' => $this->memory,
            'queries' => $this->queries
        ];
    }
}

class RequestTracker {
    private array $requests = [];
    private array $responseTypes = [];
    private array $endpoints = [];

    public function trackRequest(Request $request, Response $response): void 
    {
        $duration = microtime(true) - $request->getStartTime();
        $statusCode = $response->getStatusCode();
        $endpoint = $request->getPathInfo();

        // Track request
        $this->requests[] = [
            'method' => $request->getMethod(),
            'path' => $endpoint,