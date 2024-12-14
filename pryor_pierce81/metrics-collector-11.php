<?php

namespace App\Core\Monitoring\Metrics;

class MetricsCollector {
    private MetricsStorage $storage;
    private MetricsFormatter $formatter;
    private array $buffer = [];
    private int $bufferSize;
    private float $lastFlush;

    public function __construct(
        MetricsStorage $storage,
        MetricsFormatter $formatter,
        int $bufferSize = 1000
    ) {
        $this->storage = $storage;
        $this->formatter = $formatter;
        $this->bufferSize = $bufferSize;
        $this->lastFlush = microtime(true);
    }

    public function collect(PerformanceMetric $metric): void 
    {
        $this->buffer[] = $metric;

        if ($this->shouldFlush()) {
            $this->flush();
        }
    }

    public function getMetrics(TimeRange $range): array 
    {
        $this->flush(); // Ensure all buffered metrics are stored
        return $this->storage->query($range);
    }

    private function shouldFlush(): bool 
    {
        return count($this->buffer) >= $this->bufferSize ||
               (microtime(true) - $this->lastFlush) > 60;
    }

    private function flush(): void 
    {
        if (empty($this->buffer)) {
            return;
        }

        $formattedMetrics = $this->formatter->formatBatch($this->buffer);
        $this->storage->store($formattedMetrics);

        $this->buffer = [];
        $this->lastFlush = microtime(true);
    }
}

class MetricsStorage {
    private \PDO $connection;
    private string $table;

    public function __construct(\PDO $connection, string $table) 
    {
        $this->connection = $connection;
        $this->table = $table;
    }

    public function store(array $metrics): void 
    {
        $sql = "INSERT INTO {$this->table} (metric_key, value, tags, timestamp) VALUES (:key, :value, :tags, :timestamp)";
        $stmt = $this->connection->prepare($sql);

        foreach ($metrics as $metric) {
            $stmt->execute([
                'key' => $metric['key'],
                'value' => $metric['value'],
                'tags' => json_encode($metric['tags']),
                'timestamp' => $metric['timestamp']
            ]);
        }
    }

    public function query(TimeRange $range): array 
    {
        $sql = "SELECT * FROM {$this->table} WHERE timestamp BETWEEN :start AND :end ORDER BY timestamp ASC";
        
        $stmt = $this->connection->prepare($sql);
        $stmt->execute([
            'start' => $range->getStart(),
            'end' => $range->getEnd()
        ]);

        return array_map(function ($row) {
            $row['tags'] = json_decode($row['tags'], true);
            return $row;
        }, $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }
}

class MetricsFormatter {
    public function formatBatch(array $metrics): array 
    {
        return array_map([$this, 'formatMetric'], $metrics);
    }

    private function formatMetric(PerformanceMetric $metric): array 
    {
        return [
            'key' => $metric->getKey(),
            'value' => $metric->getValue(),
            'tags' => $metric->getTags(),
            'timestamp' => $metric->getTimestamp()
        ];
    }
}

class PerformanceAnalyzer {
    private array $analyzers;

    public function __construct(array $analyzers = []) 
    {
        $this->analyzers = $analyzers;
    }

    public function analyze(array $metrics): PerformanceReport 
    {
        $results = [];

        foreach ($this->analyzers as $analyzer) {
            $results[] = $analyzer->analyze($metrics);
        }

        return new PerformanceReport($results);
    }
}

class PerformanceReport {
    private array $results;
    private float $timestamp;

    public function __construct(array $results) 
    {
        $this->results = $results;
        $this->timestamp = microtime(true);
    }

    public function getResults(): array 
    {
        return $this->results;
    }

    public function getTimestamp(): float 
    {
        return $this->timestamp;
    }

    public function toArray(): array 
    {
        return [
            'results' => $this->results,
            'timestamp' => $this->timestamp,
            'generated_at' => date('Y-m-d H:i:s', (int)$this->timestamp)
        ];
    }
}
