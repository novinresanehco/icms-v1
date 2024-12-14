<?php

namespace App\Core\Search\Optimization;

use App\Core\Security\SecurityManager;
use App\Core\Monitoring\PerformanceMonitor;
use Illuminate\Support\Facades\DB;

class IndexOptimizer implements IndexOptimizerInterface
{
    private SecurityManager $security;
    private PerformanceMonitor $monitor;
    private array $config;

    private const OPTIMIZATION_THRESHOLD = 1000;
    private const MAX_OPTIMIZATION_TIME = 300;

    public function __construct(
        SecurityManager $security,
        PerformanceMonitor $monitor,
        array $config
    ) {
        $this->security = $security;
        $this->monitor = $monitor;
        $this->config = $config;
    }

    public function optimize(string $type): OptimizationResponse
    {
        return $this->security->executeSecureOperation(function() use ($type) {
            $operationId = $this->monitor->startOperation('index_optimize');
            
            try {
                // Check optimization need
                if (!$this->needsOptimization($type)) {
                    return new OptimizationResponse(['status' => 'not_needed']);
                }
                
                // Begin optimization
                DB::beginTransaction();
                
                // Perform optimization
                $result = $this->performOptimization($type);
                
                // Validate result
                $this->validateOptimizationResult($result);
                
                DB::commit();
                
                $this->monitor->recordSuccess($operationId);
                
                return new OptimizationResponse($result);
                
            } catch (\Exception $e) {
                DB::rollBack();
                $this->monitor->recordFailure($operationId, $e);
                throw new OptimizationException('Optimization failed: ' . $e->getMessage(), 0, $e);
            } finally {
                $this->monitor->endOperation($operationId);
            }
        }, ['operation' => 'index_optimize', 'type' => $type]);
    }

    private function needsOptimization(string $type): bool
    {
        $stats = $this->getIndexStats($type);
        
        return $stats['fragmentation'] > $this->config['fragmentation_threshold']
            || $stats['deleted_docs'] > $this->config['deletion_threshold']
            || $this->isMaintenanceScheduled($type);
    }

    private function performOptimization(string $type): array
    {
        // Set resource limits
        $this->setResourceLimits();
        
        $steps = [
            'merge_segments' => fn() => $this->mergeSegments($type),
            'remove_deletions' => fn() => $this->removeDeletions($type),
            'reindex_data' => fn() => $this->reindexData($type),
            'update_metadata' => fn() => $this->updateMetadata($type)
        ];
        
        $results = [];
        foreach ($steps as $step => $operation) {
            $results[$step] = $operation();
        }
        
        return $results;
    }

    private function mergeSegments(string $type): array
    {
        $segments = $this->getSegments($type);
        
        $merged = $this->indexManager->mergeSegments(
            $type,
            $segments,
            $this->config['merge_factor']
        );
        
        return [
            'segments_before' => count($segments),
            'segments_after' => count($merged),
            'time_taken' => $merged['time_taken']
        ];
    }

    private function removeDeletions(string $type): array
    {
        $deleted = $this->indexManager->removeDeletions($type);
        
        return [
            'docs_removed' => $deleted['count'],
            'space_reclaimed' => $deleted['bytes'],
            'time_taken' => $deleted['time_taken']
        ];
    }

    private function reindexData(string $type): array
    {
        $reindexed = $this->indexManager->reindexType(
            $type,
            $this->config['batch_size']
        );
        
        return [
            'docs_reindexed' => $reindexed['count'],
            'failed_docs' => $reindexed['failures'],
            'time_taken' => $reindexed['time_taken']
        ];
    }

    private function updateMetadata(string $type): array
    {
        $metadata = [
            'last_optimization' => now(),
            'stats' => $this->getIndexStats($type),
            'health' => $this->getIndexHealth($type)
        ];
        
        $this->indexManager->updateMetadata($type, $metadata);
        
        return $metadata;
    }

    private function validateOptimizationResult(array $result): void
    {
        foreach ($result as $step => $stats) {
            if (!$this->validateStepResults($step, $stats)) {
                throw new OptimizationException("Optimization step failed: {$step}");
            }
        }
    }

    private function validateStepResults(string $step, array $stats): bool
    {
        $thresholds = $this->config['optimization_thresholds'][$step] ?? [];
        
        foreach ($thresholds as $metric => $threshold) {
            if (isset($stats[$metric]) && !$this->isWithinThreshold($stats[$metric], $threshold)) {
                return false;
            }
        }
        
        return true;
    }

    private function setResourceLimits(): void
    {
        ini_set('max_execution_time', self::MAX_OPTIMIZATION_TIME);
        ini_set('memory_limit', $this->config['optimization_memory_limit']);
    }

    private function isWithinThreshold($value, array $threshold): bool
    {
        return $value >= ($threshold['min'] ?? PHP_FLOAT_MIN)
            && $value <= ($threshold['max'] ?? PHP_FLOAT_MAX);
    }
}
