<?php

namespace App\Core\Monitoring;

use App\Core\Exception\MetricsException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class MetricsCollector implements MetricsCollectorInterface
{
    private array $activeCollections = [];
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    public function startCollection(string $monitorId, string $component, array $options): void
    {
        if (isset($this->activeCollections[$monitorId])) {
            throw new MetricsException('Collection already active');
        }

        $this->activeCollections[$monitorId] = [
            'component' => $component,
            'options' => $options,
            'start_time' => microtime(true),
            'metrics' => []
        ];

        $this->initializeCollection($monitorId);
    }

    public function stopCollection(string $monitorId): void
    {
        if (!isset($this->activeCollections[$monitorId])) {
            throw new MetricsException('Collection not found');
        }

        $this->finalizeCollection($monitorId);
        unset($this->activeCollections[$monitorId]);
    }

    public function recordMetric(string $monitorId, string $metric, $value): void
    {
        if (!isset($this->activeCollections[$monitorId])) {
            throw new MetricsException('Collection not found');
        }

        $timestamp = microtime(true);
        $this->activeCollections[$monitorId]['metrics'][] = [
            'metric' => $metric,
            'value' => $value,
            'timestamp' => $timestamp
        ];

        $this->storeMetric($monitorId, $metric, $value, $timestamp);
    }

    public function getMetrics(string $component, array $criteria = []): array
    {
        $query = DB::table('metrics')
            ->where('component', $component);

        if (isset($criteria['start_time'])) {
            $query->where('timestamp', '>=', $criteria['start_time']);
        }

        if (isset($criteria['end_time'])) {
            $query->where('timestamp', '<=', $criteria['end_time']);
        }

        $metrics = $query->get()->toArray();
        return $this->processMetrics($metrics, $criteria);
    }

    public function getCollectionResults(string $monitorId): array
    {
        if (!isset($this->activeCollections[$monitorId])) {
            throw new MetricsException('Collection not found');
        }

        return [
            'metrics' => $this->activeCollections[$monitorId]['metrics'],
            'summary' => $this->generateSummary($monitorId)
        ];
    }

    public function getRecentMetrics(string $component): array
    {
        $cacheKey = "metrics:{$component}:recent";
        
        return Cache::remember($cacheKey, 60, function() use ($component) {
            return DB::table('metrics')
                ->where('component', $component)
                ->where('timestamp', '>=', time() - 300)
                ->get()
                ->toArray();
        });
    }

    private function initializeCollection(string $monitorId): void
    {
        $collection = &$this->activeCollections[$monitorId];
        
        $baseMetrics = $this->getBaseMetrics($collection['component']);
        foreach ($baseMetrics as $metric => $value) {
            $this->recordMetric($monitorId, $metric, $value);
        }
    }

    private function finalizeCollection(string $monitorId): void
    {
        $collection = &$this->activeCollections[$monitorId];
        
        $finalMetrics = $this->getFinalMetrics($collection['component']);
        foreach ($finalMetrics as $metric => $value) {
            $this->recordMetric($monitorId, $metric, $value);
        }
    }

    private function storeMetric(string $monitorId, string $metric, $value, float $timestamp): void
    {
        DB::table('metrics')->insert([
            'monitor_id' => $monitorId,
            'component' => $this->activeCollections[$monitorId]['component'],
            'metric' => $metric,
            'value' => $value,
            'timestamp' => $timestamp,
            'created_at' => now()
        ]);
    }

    private function processMetrics(array $metrics, array $criteria): array
    {
        if (isset($criteria['aggregation'])) {
            return $this->aggregateMetrics($metrics, $criteria['aggregation']);
        }

        return $metrics;
    }

    private function aggregateMetrics(array $metrics, string $aggregation): array
    {
        $result = [];

        foreach ($metrics as $metric) {
            $key = $metric->metric;
            
            if (!isset($result[$key])) {
                $result[$key] = ['values' => [], 'count' => 0];
            }

            $result[$key]['values'][] = $metric->value;
            $result[$key]['count']++;
        }

        return array_map(function($data) use ($aggregation) {
            return $this->calculateAggregation($data['values'], $aggregation);
        }, $result);
    }

    private function calculateAggregation(array $values, string $aggregation): float
    {
        return match ($aggregation) {
            'avg' => array_sum($values) / count($values),
            'max' => max($values),
            'min' => min($values),
            'sum' => array_sum($values),
            default => array_sum($values) / count($values)
        };
    }

    private function generateSummary(string $monitorId): array
    {
        $metrics = $this->activeCollections[$monitorId]['metrics'];
        $summary = [];

        foreach ($metrics as $metric) {
            $key = $metric['metric'];
            
            if (!isset($summary[$key])) {
                $summary[$key] = [
                    'min' => $metric['value'],
                    'max' => $metric['value'],
                    'sum' => $metric['value'],
                    'count' => 1
                ];
            } else {
                $summary[$key]['min'] = min($summary[$key]['min'], $metric['value']);
                $summary[$key]['max'] = max($summary[$key]['max'], $metric['value']);
                $summary[$key]['sum'] += $metric['value'];
                $summary[$key]['count']++;
            }
        }

        foreach ($summary as &$data) {
            $data['avg'] = $data['sum'] / $data['count'];
        }

        return $summary;
    }

    private