<?php

namespace App\Core\Metrics\Reporting;

class ReportGenerator
{
    private array $aggregators = [];
    private array $formatters = [];

    public function registerAggregator(string $type, callable $aggregator): void
    {
        $this->aggregators[$type] = $aggregator;
    }

    public function registerFormatter(string $format, callable $formatter): void
    {
        $this->formatters[$format] = $formatter;
    }

    public function generate(array $metrics, string $type, string $format): mixed
    {
        if (!isset($this->aggregators[$type])) {
            throw new \InvalidArgumentException("Unknown aggregator type: {$type}");
        }

        if (!isset($this->formatters[$format])) {
            throw new \InvalidArgumentException("Unknown format: {$format}");
        }

        $aggregator = $this->aggregators[$type];
        $formatter = $this->formatters[$format];

        $aggregatedData = $aggregator($metrics);
        return $formatter($aggregatedData);
    }
}

class TimeSeriesAggregator
{
    public function __invoke(array $metrics): array
    {
        $series = [];
        foreach ($metrics as $metric) {
            $timestamp = $this->normalizeTimestamp($metric->timestamp);
            if (!isset($series[$timestamp])) {
                $series[$timestamp] = [];
            }
            $series[$timestamp][] = $metric->value;
        }

        foreach ($series as $timestamp => $values) {
            $series[$timestamp] = $this->calculateStats($values);
        }

        ksort($series);
        return $series;
    }

    private function normalizeTimestamp(int $timestamp): int
    {
        return $timestamp - ($timestamp % 300); // 5-minute intervals
    }

    private function calculateStats(array $values): array
    {
        return [
            'min' => min($values),
            'max' => max($values),
            'avg' => array_sum($values) / count($values),
            'count' => count($values)
        ];
    }
}

class JsonReportFormatter
{
    public function __invoke(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT);
    }
}

class CsvReportFormatter
{
    public function __invoke(array $data): string
    {
        $output = fopen('php://temp', 'r+');
        
        // Write headers
        fputcsv($output, ['timestamp', 'min', 'max', 'avg', 'count']);
        
        // Write data
        foreach ($data as $timestamp => $stats) {
            fputcsv($output, [
                $timestamp,
                $stats['min'],
                $stats['max'],
                $stats['avg'],
                $stats['count']
            ]);
        }
        
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        
        return $csv;
    }
}
