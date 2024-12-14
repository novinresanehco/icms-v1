<?php

namespace App\Core\Performance;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Exception\MetricsException;
use Psr\Log\LoggerInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class MetricsCollector implements MetricsCollectorInterface
{
    private SecurityManagerInterface $security;
    private LoggerInterface $logger;
    private array $config;
    private array $metrics = [];

    public function __construct(
        SecurityManagerInterface $security,
        LoggerInterface $logger,
        array $config = []
    ) {
        $this->security = $security;
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    public function collectMetrics(string $component, array $options = []): array
    {
        $operationId = $this->generateOperationId();

        try {
            $this->security->validateSecureOperation('metrics:collect', [
                'operation_id' => $operationId,
                'component' => $component
            ]);

            $metrics = $this->gatherComponentMetrics($component, $options);
            $this->storeMetrics($operationId, $component, $metrics);
            
            return $metrics;

        } catch (\Exception $e) {
            $this->handleMetricsFailure($operationId, 'collect', $e);
            throw new MetricsException('Metrics collection failed', 0, $e);
        }
    }

    public function calculateMetrics(string $component, array $criteria = []): array
    {
        $operationId = $this->generateOperationId();

        try {
            $this->security->validateSecureOperation('metrics:calculate', [
                'operation_id' => $operationId,
                'component' => $component
            ]);

            return $this->processMetrics($component, $criteria);

        } catch (\Exception $e) {
            $this->handleMetricsFailure($operationId, 'calculate', $e);
            throw new MetricsException('Metrics calculation failed', 0, $e);
        }
    }

    public function getRealTimeMetrics(string $component): array
    {
        $operationId = $this->generateOperationId();

        try {
            $this->security->validateSecureOperation('metrics:realtime', [
                'operation_id' => $operationId,
                'component' => $component
            ]);

            return $this->collectRealTimeMetrics($component);

        } catch (\Exception $e) {
            $this->handleMetricsFailure($operationId, 'realtime', $e);
            throw new MetricsException('Real-time metrics collection failed', 0, $e);
        }
    }

    private function gatherComponentMetrics(string $component, array $options): array
    {
        $metrics = [];
        
        foreach ($this->config['metric_types'] as $type => $collector) {
            if ($this->shouldCollectMetric($type, $options)) {
                $metrics[$type] = $this->{$collector}($component);
            }
        }
        
        return $metrics;
    }

    private function collectRealTimeMetrics(string $component): array
    {
        return [
            'memory' => [
                'current' => memory_get_usage(true),
                'peak' => memory_get_peak_usage(true)
            ],
            'cpu' => [
                'load' => sys_getloadavg(),
                'process' => $this->getProcessCpuUsage()
            ],
            'database' => $this->getDatabaseMetrics(),
            'cache' => $this->getCacheMetrics(),
            'requests' => $this->getRequestMetrics()
        ];
    }

    private function processMetrics(string $component, array $criteria): array
    {
        $metrics = $this->loadStoredMetrics($component, $criteria);
        return $this->aggregateMetrics($metrics, $criteria);
    }

    private function shouldCollectMetric(string $type, array $options): bool
    {
        if (empty($options['types'])) {
            return true;
        }
        
        return in_array($type, $options['types']);
    }

    private function storeMetrics(string $operationId, string $component, array $metrics): void
    {
        DB::table('performance_metrics')->insert([
            'operation_id' => $operationId,
            'component' => $component,
            'metrics' => json_encode($metrics),
            'collected_at' => now(),
            'type' => 'standard'
        ]);

        $this->cacheMetrics($component, $metrics);
    }

    private function cacheMetrics(string $component, array $metrics): void
    {
        $key = "metrics:{$component}:latest";
        Cache::put($key, $metrics, $this->config['cache_ttl']);
    }

    private function loadStoredMetrics(string $component, array $criteria): array
    {
        $query = DB::table('performance_metrics')
            ->where('component', $component);

        if (isset($criteria['start_time'])) {
            $query->where('collected_at', '>=', $criteria['start_time']);
        }

        if (isset($criteria['end_time'])) {
            $query->where('collected_at', '<=', $criteria['end_time']);
        }

        return $query->get()->map(function($record) {
            return json_decode($record->metrics, true);
        })->toArray();
    }

    private function aggregateMetrics(array $metrics, array $criteria): array
    {
        $aggregated = [];
        
        foreach ($metrics as $metricSet) {
            foreach ($metricSet as $type => $value) {
                if (!isset($aggreg