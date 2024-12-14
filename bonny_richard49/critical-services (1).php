// App/Core/Security/IntegrityValidator.php
<?php

namespace App\Core\Security;

use App\Core\Cache\CacheManager;
use App\Exceptions\IntegrityValidationException;

class IntegrityValidator
{
    private CacheManager $cache;
    private array $algorithms = ['sha256', 'sha512'];

    public function validateRequestData(array $data): bool
    {
        // Validate data structure
        if (!$this->validateStructure($data)) {
            return false;
        }

        // Verify checksums
        if (!$this->verifyChecksums($data)) {
            return false;
        }

        // Validate data types
        if (!$this->validateDataTypes($data)) {
            return false;
        }

        return true;
    }

    public function executeWithIntegrity(callable $operation, array $context): mixed
    {
        $hash = $this->generateOperationHash($context);
        
        try {
            // Verify operation integrity
            $this->verifyOperationIntegrity($hash, $context);
            
            // Execute operation
            $result = $operation();
            
            // Validate result integrity
            $this->validateResultIntegrity($result);
            
            return $result;
            
        } catch (\Exception $e) {
            $this->handleIntegrityFailure($e, $hash, $context);
            throw $e;
        }
    }

    private function validateStructure(array $data): bool
    {
        foreach ($data as $key => $value) {
            if (!$this->isValidStructure($value)) {
                return false;
            }
        }
        return true;
    }

    private function verifyChecksums(array $data): bool
    {
        $checksum = hash('sha256', serialize($data));
        return hash_equals($data['_checksum'] ?? '', $checksum);
    }

    private function validateDataTypes(array $data): bool
    {
        foreach ($data as $key => $value) {
            if (!$this->isValidDataType($value)) {
                return false;
            }
        }
        return true;
    }

    private function generateOperationHash(array $context): string
    {
        return hash('sha512', serialize($context));
    }

    private function verifyOperationIntegrity(string $hash, array $context): void
    {
        if (!$this->cache->verifyIntegrity($hash, $context)) {
            throw new IntegrityValidationException('Operation integrity verification failed');
        }
    }

    private function validateResultIntegrity($result): void
    {
        if (!$this->isValidResult($result)) {
            throw new IntegrityValidationException('Result integrity validation failed');
        }
    }

    private function handleIntegrityFailure(\Exception $e, string $hash, array $context): void
    {
        // Log failure details
        Log::critical('Integrity validation failed', [
            'hash' => $hash,
            'context' => $context,
            'exception' => $e->getMessage()
        ]);
        
        // Clear related caches
        $this->cache->clearRelated($hash);
        
        // Notify monitoring system
        event(new IntegrityFailureEvent($hash, $context, $e));
    }
}

// App/Core/Infrastructure/PerformanceMonitor.php
<?php

namespace App\Core\Infrastructure;

use App\Core\Cache\CacheManager;

class PerformanceMonitor
{
    private MetricsCollector $metrics;
    private CacheManager $cache;
    
    private const CRITICAL_THRESHOLDS = [
        'response_time' => 200, // milliseconds
        'memory_usage' => 128 * 1024 * 1024, // 128MB
        'cpu_usage' => 80, // percentage
    ];

    public function trackOperation(callable $operation, array $context): mixed
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);
        
        try {
            // Monitor system state
            $this->monitorSystemState();
            
            // Execute operation
            $result = $operation();
            
            // Collect and analyze metrics
            $this->collectOperationMetrics(
                $startTime,
                $startMemory,
                $context
            );
            
            return $result;
            
        } catch (\Exception $e) {
            $this->handlePerformanceException($e, $context);
            throw $e;
        }
    }

    private function monitorSystemState(): void
    {
        $metrics = $this->metrics->collectSystemMetrics();
        
        foreach (self::CRITICAL_THRESHOLDS as $metric => $threshold) {
            if (($metrics[$metric] ?? 0) > $threshold) {
                throw new SystemPerformanceException(
                    "System {$metric} exceeds critical threshold"
                );
            }
        }
    }

    private function collectOperationMetrics(
        float $startTime,
        int $startMemory,
        array $context
    ): void {
        $executionTime = (microtime(true) - $startTime) * 1000;
        $memoryUsage = memory_get_usage(true) - $startMemory;
        
        $this->metrics->record([
            'execution_time' => $executionTime,
            'memory_usage' => $memoryUsage,
            'context' => $context,
            'timestamp' => now()
        ]);
        
        if ($executionTime > self::CRITICAL_THRESHOLDS['response_time']) {
            $this->handleSlowOperation($executionTime, $context);
        }
    }

    private function handlePerformanceException(
        \Exception $e,
        array $context
    ): void {
        Log::error('Performance exception occurred', [
            'exception' => $e->getMessage(),
            'context' => $context,
            'metrics' => $this->metrics->collectSystemMetrics()
        ]);
    }

    private function handleSlowOperation(
        float $executionTime,
        array $context
    ): void {
        Log::warning('Slow operation detected', [
            'execution_time' => $executionTime,
            'context' => $context,
            'system_state' => $this->metrics->collectSystemMetrics()
        ]);
    }
}