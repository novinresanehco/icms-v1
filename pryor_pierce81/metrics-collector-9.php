<?php

namespace App\Core\Metrics;

class MetricsCollector
{
    private MetricsStore $store;
    private array $collectors = [];
    private array $aggregators = [];
    private MetricsFormatter $formatter;

    public function collect(string $metric, $value, array $tags = []): void
    {
        $dataPoint = new DataPoint($metric, $value, $tags);
        $this->store->store($dataPoint);
        
        foreach ($this->collectors as $collector) {
            $collector->process($dataPoint);
        }
    }

    public function registerCollector(string $name, MetricCollector $collector): void
    {
        $this->collectors[$name] = $collector;
    }

    public function registerAggregator(string $name, MetricAggregator $aggregator): void
    {
        $this->aggregators[$name] = $aggregator;
    }

    public function query(MetricQuery $query): array
    {
        $results = $this->store->query($query);
        
        foreach ($this->aggregators as $aggregator) {
            $results = $aggregator->aggregate($results, $query);
        }

        return $this->formatter->format($results, $query->getFormat());
    }
}

class DataPoint
{
    private string $metric;
    private $value;
    private array $tags;
    private int $timestamp;

    public function __construct(string $metric, $value, array $tags = [])
    {
        $this->metric = $metric;
        $this->value = $value;
        $this->tags = $tags;
        $this->timestamp = time();
    }

    public function getMetric(): string
    {
        return $this->metric;
    }

    public function getValue()
    {
        return $this->value;
    }

    public function getTags(): array
    {
        return $this->tags;
    }

    public function getTimestamp(): int
    {
        return $this->timestamp;
    }
}

class MetricsStore
{
    private $connection;

    public function store(DataPoint $point): void
    {
        $this->connection->table('metrics')->insert([
            'metric' => $point->getMetric(),
            'value' => $point->getValue(),
            'tags' => json_encode($point->getTags()),
            'timestamp' => $point->getTimestamp()
        ]);
    }

    public function query(MetricQuery $query): array
    {
        $builder = $this->connection->table('metrics')
            ->where('metric', $query->getMetric())
            ->whereBetween('timestamp', [
                $query->getStart(),
                $query->getEnd()
            ]);

        foreach ($query->getTags() as $tag => $value) {
            $builder->where("tags->$tag", $value);
        }

        return $builder->get()->toArray();
    }
}

class MetricQuery
{
    private string $metric;
    private int $start;
    private int $end;
    private array $tags;
    private string $format;
    private array $aggregations;

    public function __construct(
        string $metric,
        int $start,
        int $end,
        array $tags = [],
        string $format = 'json',
        array $aggregations = []
    ) {
        $this->metric = $metric;
        $this->start = $start;
        $this->end = $end;
        $this->tags = $tags;
        $this->format = $format;
        $this->aggregations = $aggregations;
    }

    public function getMetric(): string
    {
        return $this->metric;
    }

    public function getStart(): int
    {
        return $this->start;
    }

    public function getEnd(): int
    {
        return $this->end;
    }

    public function getTags(): array
    {
        return $this->tags;
    }

    public function getFormat(): string
    {
        return $this->format;
    }

    public function getAggregations(): array
    {
        return $this->aggregations;
    }
}

interface MetricCollector
{
    public function process(DataPoint $point): void;
}

interface MetricAggregator
{
    public function aggregate(array $data, MetricQuery $query): array;
}

class TimeSeriesAggregator implements MetricAggregator
{
    public function aggregate(array $data, MetricQuery $query): array
    {
        $interval = $query->getAggregations()['interval'] ?? 60;
        $aggregated = [];

        foreach ($data as $point) {
            $bucket = floor($point->timestamp / $interval) * $interval;
            if (!isset($aggregated[$bucket])) {
                $aggregated[$bucket] = [];
            }
            $aggregated[$bucket][] = $point->value;
        }

        foreach ($aggregated as $timestamp => $values) {
            $aggregated[$timestamp] = array_sum($values) / count($values);
        }

        ksort($aggregated);
        return $aggregated;
    }
}

class PercentileAggregator implements MetricAggregator
{
    public function aggregate(array $data, MetricQuery $query): array
    {
        $percentiles = $query->getAggregations()['percentiles'] ?? [50, 90, 95, 99];
        $values = array_column($data, 'value');
        sort($values);
        
        $result = [];
        foreach ($percentiles as $p) {
            $result["p$p"] = $this->calculatePercentile($values, $p);
        }
        
        return $result;
    }

    private function calculatePercentile(array $values, float $percentile): float
    {
        $index = ($percentile / 100) * (count($values) - 1);
        if (floor($index) == $index) {
            return $values[$index];
        }
        $lower = $values[floor($index)];
        $upper = $values[ceil($index)];
        return $lower + ($upper - $lower) * ($index - floor($index));
    }
}

class MetricsFormatter
{
    public function format(array $data, string $format): string
    {
        switch ($format) {
            case 'json':
                return json_encode($data);
            case 'csv':
                return $this->formatCsv($data);
            case 'prometheus':
                return $this->formatPrometheus($data);
            default:
                throw new \InvalidArgumentException("Unsupported format: $format");
        }
    }

    private function formatCsv(array $data): string
    {
        $output = fopen('php://temp', 'r+');
        fputcsv($output, ['timestamp', 'value']);
        
        foreach ($data as $timestamp => $value) {
            fputcsv($output, [$timestamp, $value]);
        }
        
        rewind($output);
        return stream_get_contents($output);
    }

    private function formatPrometheus(array $data): string
    {
        $output = '';
        foreach ($data as $metric => $value) {
            $output .= "$metric{} $value " . time() . "\n";
        }
        return $output;
    }
}
