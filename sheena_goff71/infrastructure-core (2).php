<?php

namespace App\Core\Infrastructure;

use App\Core\Monitoring\MonitoringService;
use App\Core\Cache\CacheManager;
use App\Exceptions\InfrastructureException;

class InfrastructureCore implements InfrastructureInterface
{
    private MonitoringService $monitor;
    private CacheManager $cache;
    private array $config;

    public function __construct(
        MonitoringService $monitor,
        CacheManager $cache,
        array $config
    ) {
        $this->monitor = $monitor;
        $this->cache = $cache;
        $this->config = $config;
    }

    public function executeOperation(callable $operation, array $context): mixed
    {
        $operationId = $this->monitor->startOperation('infrastructure.execute');

        try {
            // Set resource limits
            $this->setResourceLimits();
            
            // Monitor resources
            $this->startResourceMonitoring($operationId);

            // Execute with protection
            $result = $this->executeWithProtection($operation, $context);

            // Verify system state
            $this->verifySystemState();

            return $result;

        } catch (\Throwable $e) {
            $this->handleOperationFailure($e, $operationId);
            throw new InfrastructureException('Operation failed', 0, $e);
        } finally {
            $this->monitor->stopOperation($operationId);
        }
    }

    private function setResourceLimits(): void
    {
        ini_set('memory_limit', $this->config['memory_limit']);
        ini_set('max_execution_time', $this->config['max_execution_time']);
        
        if ($this->config['strict_mode']) {
            error_reporting(E_ALL | E_STRICT);
            set_error_handler([$this, 'handleError']);
        }
    }

    private function startResourceMonitoring(string $operationId): void
    {
        $this->monitor->trackResource('memory', memory_get_usage(true));
        $this->monitor->trackResource('cpu', sys_getloadavg()[0]);
        $this->monitor->trackResource('connections', $this->getActiveConnections());
    }

    private function executeWithProtection(callable $operation, array $context): mixed
    {
        return DB::transaction(function() use ($operation, $context) {
            // Set transaction isolation level
            DB::statement('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
            
            // Execute operation
            $result = $operation();
            
            // Verify transaction state
            $this->verifyTransactionState();
            
            return $result;
        });
    }

    private function verifySystemState(): void
    {
        $metrics = [
            'memory_usage' => memory_get_usage(true),
            'cpu_usage' => sys_getloadavg()[0],
            'connections' => $this->getActiveConnections(),
            'cache_usage' => $this->cache->getUsage()
        ];

        foreach ($metrics as $metric => $value) {
            if (!$this->isMetricWithinLimits($metric, $value)) {
                throw new InfrastructureException("System metric out of bounds: $metric");
            }
        }
    }

    private function handleOperationFailure(\Throwable $e, string $operationId): void
    {
        $this->monitor->logEvent('infrastructure_failure', [
            'operation_id' => $operationId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'metrics' => [
                'memory' => memory_get_usage(true),
                'cpu' => sys_getloadavg()[0]
            ]
        ]);
    }

    private function getActiveConnections(): int
    {
        return DB::select('SELECT COUNT(*) as count FROM pg_stat_activity')[0]->count;
    }

    private function isMetricWithinLimits(string $metric, $value): bool
    {
        return $value <= $this->config["limits.$metric"];
    }

    private function verifyTransactionState(): void
    {
        if (DB::transactionLevel() > 1) {
            throw new InfrastructureException('Invalid transaction nesting detected');
        }
    }

    private function handleError($severity, $message, $file, $line): void
    {
        throw new ErrorException($message, 0, $severity, $file, $line);
    }
}
