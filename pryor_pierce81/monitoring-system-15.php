<?php

namespace App\Core\Monitoring\Jobs\Metrics;

class JobMetricsCollector
{
    private MetricsStorage $storage;
    private PerformanceAnalyzer $analyzer;
    private AlertManager $alertManager;
    private array $collectedMetrics = [];

    public function __construct(
        MetricsStorage $storage,
        PerformanceAnalyzer $analyzer,
        AlertManager $alertManager
    ) {
        $this->storage = $storage;
        $this->analyzer = $analyzer;
        $this->alertManager = $alertManager;
    }

    public function startCollection(Job $job): void
    {
        $this->collectedMetrics[$job->getId()] = [
            'start_time' => microtime(true),
            'memory_start' => memory_get_usage(),
            'job_type' => $job->getType(),
            'metrics' => []
        ];
    }

    public function recordMetric(string $jobId, string $key, $value): void
    {
        if (!isset($this->collectedMetrics[$jobId])) {
            throw new MetricsException("No metrics collection started for job {$jobId}");
        }

        $this->collectedMetrics[$jobId]['metrics'][$key] = [
            'value' => $value,
            'timestamp' => microtime(true)
        ];
    }

    public function finishCollection(string $jobId, ExecutionResult $result): JobMetrics
    {
        if (!isset($this->collectedMetrics[$jobId])) {
            throw new MetricsException("No metrics collection started for job {$jobId}");
        }

        $metrics = $this->collectedMetrics[$jobId];
        $endTime = microtime(true);
        $memoryEnd = memory_get_usage();

        $jobMetrics = new JobMetrics([
            'job_id' => $jobId,
            'duration' => $endTime - $metrics['start_time'],
            'memory_usage' => $memoryEnd - $metrics['memory_start'],
            'success' => $result->isSuccess(),
            'metrics' => $metrics['metrics']
        ]);

        // Analyze metrics and store
        $this->analyzeAndStore($jobMetrics);

        unset($this->collectedMetrics[$jobId]);

        return $jobMetrics;
    }

    private function analyzeAndStore(JobMetrics $metrics): void
    {
        // Analyze performance
        $analysis = $this->analyzer->analyze($metrics);

        // Check for performance issues
        if ($analysis->hasIssues()) {
            $this->alertManager->notify(
                new PerformanceAlert($analysis->getIssues())
            );
        }

        // Store metrics
        $this->storage->store($metrics);
    }
}

class JobMetrics
{
    private string $jobId;
    private float $duration;
    private int $memoryUsage;
    private bool $success;
    private array $metrics;
    private array $tags;

    public function __construct(array $data)
    {
        $this->jobId = $data['job_id'];
        $this->duration = $data['duration'];
        $this->memoryUsage = $data['memory_usage'];
        $this->success = $data['success'];
        $this->metrics = $data['metrics'];
        $this->tags = $data['tags'] ?? [];
    }

    public function addTag(string $key, string $value): void
    {
        $this->tags[$key] = $value;
    }

    public function getMetric(string $key): ?array
    {
        return $this->metrics[$key] ?? null;
    }

    public function getDuration(): float
    {
        return $this->duration;
    }

    public function getMemoryUsage(): int
    {
        return $this->memoryUsage;
    }

    public function getTags(): array
    {
        return $this->tags;
    }
}

class PerformanceAnalyzer
{
    private array $thresholds;
    private array $patterns;

    public function analyze(JobMetrics $metrics): PerformanceAnalysis
    {
        $issues = [];

        // Check duration threshold
        if ($metrics->getDuration() > $this->thresholds['duration']) {
            $issues[] = new PerformanceIssue(
                'duration_exceeded',
                "Job execution exceeded duration threshold",
                [
                    'actual' => $metrics->getDuration(),
                    'threshold' => $this->thresholds['duration']
                ]
            );
        }

        // Check memory usage
        if ($metrics->getMemoryUsage() > $this->thresholds['memory']) {
            $issues[] = new PerformanceIssue(
                'memory_exceeded',
                "Job exceeded memory usage threshold",
                [
                    'actual' => $metrics->getMemoryUsage(),
                    'threshold' => $this->thresholds['memory']
                ]
            );
        }

        // Check for patterns
        foreach ($this->patterns as $pattern) {
            if ($pattern->matches($metrics)) {
                $issues[] = new PerformanceIssue(
                    'pattern_detected',
                    "Performance pattern detected: {$pattern->getName()}",
                    $pattern->getDetails($metrics)
                );
            }
        }

        return new PerformanceAnalysis($issues);
    }
}

class PerformanceAnalysis
{
    private array $issues;
    private float $score;

    public function __construct(array $issues)
    {
        $this->issues = $issues;
        $this->score = $this->calculateScore();
    }

    public function hasIssues(): bool
    {
        return !empty($this->issues);
    }

    public function getIssues(): array
    {
        return $this->issues;
    }

    public function getScore(): float
    {
        return $this->score;
    }

    private function calculateScore(): float
    {
        if (empty($this->issues)) {
            return 1.0;
        }

        $totalImpact = array_sum(array_map(
            fn($issue) => $issue->getImpact(),
            $this->issues
        ));

        return max(0, 1 - ($totalImpact / 10));
    }
}

class MetricsStorage
{
    private PDO $db;
    private string $table;
    private MetricsFormatter $formatter;

    public function store(JobMetrics $metrics): void
    {
        $formatted = $this->formatter->format($metrics);
        
        $sql = "INSERT INTO {$this->table} 
                (job_id, duration, memory_usage, success, metrics, tags, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $metrics->getJobId(),
            $metrics->getDuration(),
            $metrics->getMemoryUsage(),
            $metrics->isSuccess(),
            json_encode($formatted['metrics']),
            json_encode($metrics->getTags())
        ]);
    }

    public function getMetrics(array $criteria): array
    {
        $conditions = [];
        $params = [];

        if (isset($criteria['job_type'])) {
            $conditions[] = "job_type = ?";
            $params[] = $criteria['job_type'];
        }

        if (isset($criteria['start_date'])) {
            $conditions[] = "created_at >= ?";
            $params[] = $criteria['start_date'];
        }

        if (isset($criteria['end_date'])) {
            $conditions[] = "created_at <= ?";
            $params[] = $criteria['end_date'];
        }

        $whereClause = empty($conditions) ? "" : "WHERE " . implode(" AND ", $conditions);

        $sql = "SELECT * FROM {$this->table} {$whereClause} ORDER BY created_at DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

class AlertManager
{
    private array $channels;
    private AlertFormatter $formatter;
    private ThresholdManager $thresholdManager;

    public function notify(PerformanceAlert $alert): void
    {
        if (!$this->shouldNotify($alert)) {
            return;
        }

        $formatted = $this->formatter->format($alert);

        foreach ($this->channels as $channel) {
            try {
                $channel->send($formatted);
            } catch (\Exception $e) {
                // Log failed notification attempt
                Log::error("Failed to send alert through channel: " . get_class($channel), [
                    'error' => $e->getMessage(),
                    'alert' => $formatted
                ]);
            }
        }
    }

    private function shouldNotify(PerformanceAlert $alert): bool
    {
        return $alert->getSeverity() >= $this->thresholdManager->getNotificationThreshold();
    }
}
