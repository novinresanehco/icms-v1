<?php

namespace App\Core\Metrics;

class MetricsManager implements MetricsInterface
{
    private StorageManager $storage;
    private SecurityManager $security;
    private ValidationService $validator;
    private AggregationEngine $aggregator;
    private AlertSystem $alerts;
    private AuditLogger $logger;

    private array $collectors = [];
    private array $thresholds = [];

    public function __construct(
        StorageManager $storage,
        SecurityManager $security,
        ValidationService $validator,
        AggregationEngine $aggregator,
        AlertSystem $alerts,
        AuditLogger $logger
    ) {
        $this->storage = $storage;
        $this->security = $security;
        $this->validator = $validator;
        $this->aggregator = $aggregator;
        $this->alerts = $alerts;
        $this->logger = $logger;
    }

    public function collect(string $metric, mixed $value, array $tags = []): void
    {
        $metricId = uniqid('metric_', true);

        try {
            $this->validateMetric($metric, $value, $tags);
            $this->security->validateMetricAccess($metric);

            $processedValue = $this->processMetricValue($value);
            $this->storeMetric($metricId, $metric, $processedValue, $tags);
            
            $this->checkThresholds($metric, $processedValue, $tags);
            $this->aggregator->processMetric($metric, $processedValue, $tags);

        } catch (\Exception $e) {
            $this->handleCollectionFailure($metricId, $metric, $e);
            throw new MetricException('Metric collection failed', 0, $e);
        }
    }

    public function registerCollector(string $name, MetricCollector $collector): void
    {
        $registrationId = uniqid('collector_', true);

        try {
            $this->validateCollector($name, $collector);
            $this->security->validateCollectorRegistration($collector);

            $this->collectors[$name] = $collector;
            $this->logger->logCollectorRegistration($registrationId, $name);

        } catch (\Exception $e) {
            $this->handleRegistrationFailure($registrationId, $name, $e);
            throw new RegistrationException('Collector registration failed', 0, $e);
        }
    }

    public function setThreshold(string $metric, float $threshold, string $condition): void
    {
        $this->validateThreshold($metric, $threshold, $condition);
        
        $this->thresholds[$metric] = [
            'value' => $threshold,
            'condition' => $condition,
            'created_at' => now()
        ];
    }

    public function query(string $metric, array $criteria = []): array
    {
        $queryId = uniqid('query_', true);

        try {
            $this->validateQueryCriteria($criteria);
            $this->security->validateMetricQuery($metric);

            $results = $this->storage->query($metric, $criteria);
            $this->logger->logMetricQuery($queryId, $metric);

            return $this->aggregator->processResults($results, $criteria);

        } catch (\Exception $e) {
            $this->handleQueryFailure($queryId, $metric, $e);
            throw new QueryException('Metric query failed', 0, $e);
        }
    }

    public function aggregate(string $metric, string $function, array $options = []): float
    {
        $aggregationId = uniqid('agg_', true);

        try {
            $this->validateAggregation($function, $options);
            $this->security->validateAggregationAccess($metric);

            $result = $this->aggregator->aggregate($metric, $function, $options);
            $this->logger->logAggregation($aggregationId, $metric, $function);

            return $result;

        } catch (\Exception $e) {
            $this->handleAggregationFailure($aggregationId, $metric, $e);
            throw new AggregationException('Metric aggregation failed', 0, $e);
        }
    }

    private function validateMetric(string $metric, mixed $value, array $tags): void
    {
        if (!$this->validator->validateMetricName($metric)) {
            throw new ValidationException('Invalid metric name');
        }

        if (!$this->validator->validateMetricValue($value)) {
            throw new ValidationException('Invalid metric value');
        }

        if (!$this->validator->validateMetricTags($tags)) {
            throw new ValidationException('Invalid metric tags');
        }
    }

    private function processMetricValue(mixed $value): float
    {
        if (is_numeric($value)) {
            return (float)$value;
        }

        foreach ($this->collectors as $collector) {
            if ($collector->supports($value)) {
                return $collector->process($value);
            }
        }

        throw new ProcessingException('Unsupported metric value type');
    }

    private function storeMetric(string $metricId, string $metric, float $value, array $tags): void
    {
        $metricData = [
            'id' => $metricId,
            'name' => $metric,
            'value' => $value,
            'tags' => $tags,
            'timestamp' => microtime(true),
            'hostname' => gethostname()
        ];

        $this->storage->store($metricData);
        $this->logger->logMetricStorage($metricId, $metric);
    }

    private function checkThresholds(string $metric, float $value, array $tags): void
    {
        if (!isset($this->thresholds[$metric])) {
            return;
        }

        $threshold = $this->thresholds[$metric];
        $exceeded = $this->evaluateThreshold($value, $threshold);

        if ($exceeded) {
            $this->handleThresholdExceeded($metric, $value, $threshold, $tags);
        }
    }

    private function evaluateThreshold(float $value, array $threshold): bool
    {
        return match ($threshold['condition']) {
            '>' => $value > $threshold['value'],
            '<' => $value < $threshold['value'],
            '>=' => $value >= $threshold['value'],
            '<=' => $value <= $threshold['value'],
            '=' => abs($value - $threshold['value']) < PHP_FLOAT_EPSILON,
            default => throw new ValidationException('Invalid threshold condition')
        };
    }

    private function handleThresholdExceeded(
        string $metric,
        float $value,
        array $threshold,
        array $tags
    ): void {
        $this->alerts->triggerThresholdAlert($metric, [
            'value' => $value,
            'threshold' => $threshold['value'],
            'condition' => $threshold['condition'],
            'tags' => $tags
        ]);

        $this->logger->logThresholdExceeded($metric, $value, $threshold);
    }

    private function validateCollector(string $name, MetricCollector $collector): void
    {
        if (!$this->validator->validateCollectorName($name)) {
            throw new ValidationException('Invalid collector name');
        }

        if (isset($this->collectors[$name])) {
            throw new DuplicateException('Collector already registered');
        }
    }

    private function validateQueryCriteria(array $criteria): void
    {
        if (!$this->validator->validateQueryCriteria($criteria)) {
            throw new ValidationException('Invalid query criteria');
        }
    }

    private function validateAggregation(string $function, array $options): void
    {
        if (!$this->validator->validateAggregationFunction($function)) {
            throw new ValidationException('Invalid aggregation function');
        }

        if (!$this->validator->validateAggregationOptions($options)) {
            throw new ValidationException('Invalid aggregation options');
        }
    }

    private function handleCollectionFailure(string $metricId, string $metric, \Exception $e): void
    {
        $this->logger->logCollectionFailure($metricId, [
            'metric' => $metric,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        if ($e instanceof SecurityException) {
            $this->security->handleSecurityIncident($metricId, $e);
        }
    }
}
