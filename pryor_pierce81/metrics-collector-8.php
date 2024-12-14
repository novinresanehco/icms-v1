<?php

namespace App\Core\Monitoring\Metrics;

class MetricsCollector
{
    private MetricRegistry $registry;
    private DataSourceManager $sourceManager;
    private ProcessingPipeline $pipeline;
    private StorageManager $storage;
    private AlertManager $alerts;

    public function collect(string $metricType): MetricBatch
    {
        $metric = $this->registry->getMetric($metricType);
        $sources = $this->sourceManager->getSourcesForMetric($metric);
        $batch = new MetricBatch($metric);

        foreach ($sources as $source) {
            try {
                $data = $source->collect();
                $processed = $this->pipeline->process($data, $metric->getProcessors());
                $batch->addData($source->getName(), $processed);
            } catch (CollectionException $e) {
                $this->handleCollectionError($e, $source, $metric);
            }
        }

        $this->storage->store($batch);
        $this->checkThresholds($batch);

        return $batch;
    }

    public function collectAll(): array
    {
        $batches = [];
        foreach ($this->registry->getMetrics() as $metric) {
            $batches[] = $this->collect($metric->getType());
        }
        return $batches;
    }

    private function handleCollectionError(
        CollectionException $e,
        DataSource $source,
        Metric $metric
    ): void {
        $this->alerts->notify(new CollectionAlert(
            $source->getName(),
            $metric->getType(),
            $e->getMessage()
        ));
    }

    private function checkThresholds(MetricBatch $batch): void
    {
        $thresholdChecker = new ThresholdChecker($this->alerts);
        $thresholdChecker->check($batch);
    }
}

class ProcessingPipeline
{
    private array $globalProcessors = [];
    
    public function process(array $data, array $processors): array
    {
        $processedData = $data;

        foreach ($this->globalProcessors as $processor) {
            $processedData = $processor->process($processedData);
        }

        foreach ($processors as $processor) {
            $processedData = $processor->process($processedData);
        }

        return $processedData;
    }

    public function addGlobalProcessor(DataProcessor $processor): void
    {
        $this->globalProcessors[] = $processor;
    }
}

class DataSourceManager
{
    private array $sources = [];
    private SourceFactory $factory;
    private HealthChecker $healthChecker;

    public function getSourcesForMetric(Metric $metric): array
    {
        $sources = [];
        foreach ($metric->getSourceTypes() as $sourceType) {
            if ($source = $this->getSource($sourceType)) {
                if ($this->healthChecker->isHealthy($source)) {
                    $sources[] = $source;
                }
            }
        }
        return $sources;
    }

    public function registerSource(string $type, DataSource $source): void
    {
        $this->sources[$type] = $source;
    }

    private function getSource(string $type): ?DataSource
    {
        return $this->sources[$type] ?? $this->factory->createSource($type);
    }
}

class StorageManager
{
    private array $storageEngines;
    private StorageRouter $router;
    private RetryManager $retryManager;

    public function store(MetricBatch $batch): void
    {
        $engines = $this->router->getEnginesForBatch($batch);
        
        foreach ($engines as $engine) {
            try {
                $engine->store($batch);
            } catch (StorageException $e) {
                if ($this->retryManager->shouldRetry($e)) {
                    $this->retryManager->scheduleRetry($engine, $batch);
                } else {
                    throw $e;
                }
            }
        }
    }
}

class MetricBatch
{
    private Metric $metric;
    private array $data = [];
    private float $timestamp;
    private array $metadata = [];

    public function __construct(Metric $metric)
    {
        $this->metric = $metric;
        $this->timestamp = microtime(true);
    }

    public function addData(string $source, array $data): void
    {
        $this->data[$source] = $data;
    }

    public function getMetric(): Metric
    {
        return $this->metric;
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function getTimestamp(): float
    {
        return $this->timestamp;
    }

    public function addMetadata(string $key, $value): void
    {
        $this->metadata[$key] = $value;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }
}

class ThresholdChecker
{
    private AlertManager $alerts;
    private array $thresholds = [];

    public function check(MetricBatch $batch): void
    {
        $thresholds = $this->getThresholdsForMetric($batch->getMetric());

        foreach ($batch->getData() as $source => $data) {
            foreach ($thresholds as $threshold) {
                if ($threshold->isExceeded($data)) {
                    $this->alerts->notify(new ThresholdAlert(
                        $batch->getMetric(),
                        $threshold,
                        $data
                    ));
                }
            }
        }
    }

    private function getThresholdsForMetric(Metric $metric): array
    {
        return $this->thresholds[$metric->getType()] ?? [];
    }
}

interface DataProcessor
{
    public function process(array $data): array;
}

class AggregationProcessor implements DataProcessor
{
    private string $aggregationType;

    public function process(array $data): array
    {
        switch ($this->aggregationType) {
            case 'sum':
                return ['value' => array_sum($data)];
            case 'average':
                return ['value' => array_sum($data) / count($data)];
            case 'max':
                return ['value' => max($data)];
            case 'min':
                return ['value' => min($data)];
            default:
                return $data;
        }
    }
}

class FilterProcessor implements DataProcessor
{
    private $predicate;

    public function process(array $data): array
    {
        return array_filter($data, $this->predicate);
    }
}

class TransformProcessor implements DataProcessor
{
    private $transformer;

    public function process(array $data): array
    {
        return array_map($this->transformer, $data);
    }
}

interface DataSource
{
    public function getName(): string;
    public function collect(): array;
    public function isHealthy(): bool;
}

class DatabaseMetricsSource implements DataSource
{
    private \PDO $db;
    private string $query;
    
    public function collect(): array
    {
        $stmt = $this->db->prepare($this->query);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function getName(): string
    {
        return 'database';
    }

    public function isHealthy(): bool
    {
        try {
            $this->db->query('SELECT 1');
            return true;
        } catch (\PDOException $e) {
            return false;
        }
    }
}