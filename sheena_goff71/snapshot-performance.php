<?php

namespace App\Core\Events\Snapshots\Performance;

class PerformanceOptimizer
{
    private CompressionManager $compression;
    private CacheManager $cache;
    private StorageOptimizer $storage;
    private PerformanceMetrics $metrics;

    public function __construct(
        CompressionManager $compression,
        CacheManager $cache,
        StorageOptimizer $storage,
        PerformanceMetrics $metrics
    ) {
        $this->compression = $compression;
        $this->cache = $cache;
        $this->storage = $storage;
        $this->metrics = $metrics;
    }

    public function optimizeSnapshot(Snapshot $snapshot): OptimizedSnapshot
    {
        $startTime = microtime(true);

        try {
            // Compress snapshot data
            $compressedState = $this->compression->compress($snapshot->getState());
            
            // Optimize storage format
            $optimizedData = $this->storage->optimize($compressedState);
            
            // Create optimized snapshot
            $optimizedSnapshot = new OptimizedSnapshot(
                $snapshot->getAggregateId(),
                $optimizedData,
                $snapshot->getVersion()
            );

            // Cache the optimized snapshot
            $this->cache->store($optimizedSnapshot);

            $this->metrics->recordOptimization(
                $snapshot,
                $optimizedSnapshot,
                microtime(true) - $startTime
            );

            return $optimizedSnapshot;

        } catch (\Exception $e) {
            $this->metrics->recordOptimizationFailure($snapshot, $e);
            throw new OptimizationException("Optimization failed: " . $e->getMessage(), 0, $e);
        }
    }

    public function batchOptimize(array $snapshots): array
    {
        $optimizedSnapshots = [];
        $failures = [];

        foreach ($snapshots as $snapshot) {
            try {
                $optimizedSnapshots[] = $this->optimizeSnapshot($snapshot);
            } catch (\Exception $e) {
                $failures[] = [
                    'snapshot' => $snapshot,
                    'error' => $e
                ];
            }
        }

        if (!empty($failures)) {
            $this->handleBatchFailures($failures);
        }

        return $optimizedSnapshots;
    }

    private function handleBatchFailures(array $failures): void
    {
        foreach ($failures as $failure) {
            $this->metrics->recordOptimizationFailure(
                $failure['snapshot'],
                $failure['error']
            );
        }
    }
}

class CompressionManager
{
    private array $algorithms;
    private array $options;

    public function __construct(array $algorithms = ['zlib', 'gzip'], array $options = [])
    {
        $this->algorithms = $algorithms;
        $this->options = $options;
    }

    public function compress($data): CompressedData
    {
        $results = [];
        $originalSize = strlen(serialize($data));

        foreach ($this->algorithms as $algorithm) {
            try {
                $compressed = $this->compressWithAlgorithm($data, $algorithm);
                $results[$algorithm] = [
                    'data' => $compressed,
                    'size' => strlen($compressed),
                    'ratio' => $this->calculateCompressionRatio($originalSize, strlen($compressed))
                ];
            } catch (\Exception $e) {
                continue;
            }
        }

        // Select best compression result
        $best = $this->selectBestCompression($results);
        
        return new CompressedData(
            $best['data'],
            $best['algorithm'],
            $originalSize,
            $best['size']
        );
    }

    private function compressWithAlgorithm($data, string $algorithm): string
    {
        return match($algorithm) {
            'zlib' => gzcompress(serialize($data), $this->options['level'] ?? -1),
            'gzip' => gzencode(serialize($data), $this->options['level'] ?? -1),
            default => throw new \InvalidArgumentException("Unsupported compression algorithm: {$algorithm}")
        };
    }

    private function calculateCompressionRatio(int $originalSize, int $compressedSize): float
    {
        if ($originalSize === 0) {
            return 1.0;
        }
        return 1 - ($compressedSize / $originalSize);
    }

    private function selectBestCompression(array $results): array
    {
        if (empty($results)) {
            throw new CompressionException("No successful compression results");
        }

        $best = null;
        $bestRatio = -1;

        foreach ($results as $algorithm => $result) {
            if ($result['ratio'] > $bestRatio) {
                $best = $result + ['algorithm' => $algorithm];
                $bestRatio = $result['ratio'];
            }
        }

        return $best;
    }
}

class StorageOptimizer
{
    private array $optimizationStrategies;

    public function __construct(array $strategies = [])
    {
        $this->optimizationStrategies = $strategies;
    }

    public function optimize($data): OptimizedData
    {
        $originalSize = strlen($data);
        $bestResult = null;
        $bestSize = PHP_INT_MAX;

        foreach ($this->optimizationStrategies as $strategy) {
            try {
                $result = $strategy->optimize($data);
                if ($result->getSize() < $bestSize) {
                    $bestResult = $result;
                    $bestSize = $result->getSize();
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        if ($bestResult === null) {
            throw new OptimizationException("No successful optimization strategies");
        }

        return $bestResult;
    }

    public function addStrategy(OptimizationStrategy $strategy): void
    {
        $this->optimizationStrategies[] = $strategy;
    }
}

class PerformanceMetrics
{
    private MetricsCollector $collector;

    public function __construct(MetricsCollector $collector)
    {
        $this->collector = $collector;
    }

    public function recordOptimization(
        Snapshot $original,
        OptimizedSnapshot $optimized,
        float $duration
    ): void {
        $compressionRatio = $this->calculateCompressionRatio(
            $original->getSize(),
            $optimized->getSize()
        );

        $this->collector->gauge(
            'snapshot.optimization.compression_ratio',
            $compressionRatio,
            ['aggregate_id' => $original->getAggregateId()]
        );

        $this->collector->timing(
            'snapshot.optimization.duration',
            $duration * 1000,
            ['aggregate_id' => $original->getAggregateId()]
        );

        $this->collector->increment('snapshot.optimization.completed');
    }

    public function recordOptimizationFailure(Snapshot $snapshot, \Exception $error): void
    {
        $this->collector->increment('snapshot.optimization.failed', [
            'aggregate_id' => $snapshot->getAggregateId(),
            'error_type' => get_class($error)
        ]);
    }

    private function calculateCompressionRatio(int $originalSize, int $optimizedSize): float
    {
        if ($originalSize === 0) {
            return 1.0;
        }
        return 1 - ($optimizedSize / $originalSize);
    }
}

class OptimizedSnapshot
{
    private string $aggregateId;
    private OptimizedData $data;
    private int $version;
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $aggregateId,
        OptimizedData $data,
        int $version
    ) {
        $this->aggregateId = $aggregateId;
        $this->data = $data;
        $this->version = $version;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getAggregateId(): string
    {
        return $this->aggregateId;
    }

    public function getData(): OptimizedData
    {
        return $this->data;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getSize(): int
    {
        return $this->data->getSize();
    }
}

class OptimizationException extends \Exception {}
class CompressionException extends \Exception {}

interface OptimizationStrategy
{
    public function optimize($data): OptimizedData;
    public function getName(): string;
}

class CompressedData
{
    private string $data;
    private string $algorithm;
    private int $originalSize;
    private int $compressedSize;

    public function __construct(
        string $data,
        string $algorithm,
        int $originalSize,
        int $compressedSize
    ) {
        $this->data = $data;
        $this->algorithm = $algorithm;
        $this->originalSize = $originalSize;
        $this->compressedSize = $compressedSize;
    }

    public function getData(): string
    {
        return $this->data;
    }

    public function getAlgorithm(): string
    {
        return $this->algorithm;
    }

    public function getOriginalSize(): int
    {
        return $this->originalSize;
    }

    public function getCompressedSize(): int
    {
        return $this->compressedSize;
    }

    public function getCompressionRatio(): float
    {
        return 1 - ($this->compressedSize / $this->originalSize);
    }
}

