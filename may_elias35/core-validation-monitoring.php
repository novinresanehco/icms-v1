<?php

namespace App\Core\Services;

use Illuminate\Support\Facades\Cache;
use App\Core\Security\SecurityManager;
use App\Core\Interfaces\{
    ValidationInterface,
    MonitoringInterface,
    CacheInterface
};
use App\Core\Events\ValidationEvent;
use App\Core\Exceptions\{
    ValidationException,
    SecurityException,
    PerformanceException
};

class ValidationService implements ValidationInterface 
{
    private array $rules = [];
    private AuditLogger $logger;
    private EncryptionService $encryption;
    private MonitoringService $monitor;

    public function validateData(array $data, array $rules = []): array 
    {
        $operationId = $this->monitor->startOperation('validation');
        
        try {
            $rules = $rules ?: $this->getRulesForData($data);
            $this->validateRules($data, $rules);
            $this->validateSecurity($data);
            $this->validatePerformance($data);
            
            $this->logger->logValidation($data, $rules);
            
            return $data;
        } catch (\Exception $e) {
            $this->logger->logValidationFailure($data, $rules, $e);
            throw new ValidationException($e->getMessage(), 0, $e);
        } finally {
            $this->monitor->endOperation($operationId);
        }
    }

    private function validateRules(array $data, array $rules): void 
    {
        foreach ($rules as $field => $rule) {
            if (!isset($data[$field]) && strpos($rule, 'required') !== false) {
                throw new ValidationException("Field $field is required");
            }

            if (isset($data[$field])) {
                $this->validateField($field, $data[$field], $rule);
            }
        }
    }

    private function validateField(string $field, $value, string $rule): void 
    {
        $rules = explode('|', $rule);
        
        foreach ($rules as $rule) {
            if (!$this->checkRule($value, $rule)) {
                throw new ValidationException("Validation failed for $field: $rule");
            }
        }
    }

    private function validateSecurity(array $data): void 
    {
        foreach ($data as $field => $value) {
            if ($this->isSensitiveField($field)) {
                if (!$this->encryption->isEncrypted($value)) {
                    throw new SecurityException("Sensitive field $field must be encrypted");
                }
            }
        }
    }

    private function validatePerformance(array $data): void 
    {
        $size = strlen(serialize($data));
        
        if ($size > config('validation.max_payload_size')) {
            throw new PerformanceException('Data payload exceeds maximum size');
        }
    }
}

class MonitoringService implements MonitoringInterface 
{
    private CacheInterface $cache;
    private AuditLogger $logger;

    public function startOperation(string $type): string 
    {
        $id = uniqid('op_', true);
        
        $this->cache->put("operation.$id", [
            'type' => $type,
            'start_time' => microtime(true),
            'memory_start' => memory_get_usage(),
            'status' => 'started'
        ]);

        return $id;
    }

    public function endOperation(string $id): void 
    {
        $operation = $this->cache->get("operation.$id");
        
        if (!$operation) {
            throw new MonitoringException("Operation $id not found");
        }

        $endTime = microtime(true);
        $duration = $endTime - $operation['start_time'];
        $memoryUsed = memory_get_usage() - $operation['memory_start'];

        $operation['end_time'] = $endTime;
        $operation['duration'] = $duration;
        $operation['memory_used'] = $memoryUsed;
        $operation['status'] = 'completed';

        $this->cache->put("operation.$id", $operation);
        $this->validateMetrics($operation);
        $this->logger->logOperation($operation);
    }

    public function recordMetric(string $name, $value): void 
    {
        $this->cache->increment("metric.$name", $value);
        
        if ($this->shouldAlertMetric($name, $value)) {
            $this->triggerMetricAlert($name, $value);
        }
    }

    public function getMetrics(): array 
    {
        return [
            'response_times' => $this->getAverageResponseTimes(),
            'error_rates' => $this->getErrorRates(),
            'resource_usage' => $this->getResourceUsage(),
            'cache_hits' => $this->getCacheHitRates()
        ];
    }

    private function validateMetrics(array $operation): void 
    {
        $thresholds = config('monitoring.thresholds');

        if ($operation['duration'] > $thresholds['max_duration']) {
            $this->triggerPerformanceAlert($operation);
        }

        if ($operation['memory_used'] > $thresholds['max_memory']) {
            $this->triggerResourceAlert($operation);
        }
    }

    private function shouldAlertMetric(string $name, $value): bool 
    {
        $thresholds = config('monitoring.thresholds');
        return isset($thresholds[$name]) && $value > $thresholds[$name];
    }

    private function triggerMetricAlert(string $name, $value): void 
    {
        $this->logger->logAlert([
            'type' => 'metric_threshold',
            'metric' => $name,
            'value' => $value,
            'timestamp' => now()
        ]);
    }

    private function triggerPerformanceAlert(array $operation): void 
    {
        $this->logger->logAlert([
            'type' => 'performance_threshold',
            'operation' => $operation,
            'timestamp' => now()
        ]);
    }

    private function triggerResourceAlert(array $operation): void 
    {
        $this->logger->logAlert([
            'type' => 'resource_threshold',
            'operation' => $operation,
            'timestamp' => now()
        ]);
    }

    private function getAverageResponseTimes(): array 
    {
        $operations = $this->cache->get('operations') ?: [];
        
        return array_reduce($operations, function($carry, $op) {
            $carry[$op['type']] = ($carry[$op['type']] ?? 0) + $op['duration'];
            return $carry;
        }, []);
    }

    private function getErrorRates(): array 
    {
        return [
            'validation' => $this->cache->get('error_rate.validation') ?: 0,
            'security' => $this->cache->get('error_rate.security') ?: 0,
            'system' => $this->cache->get('error_rate.system') ?: 0
        ];
    }

    private function getResourceUsage(): array 
    {
        return [
            'memory' => memory_get_usage(true),
            'cpu' => sys_getloadavg()[0],
            'disk' => disk_free_space('/')
        ];
    }

    private function getCacheHitRates(): array 
    {
        $hits = $this->cache->get('cache.hits') ?: 0;
        $misses = $this->cache->get('cache.misses') ?: 0;
        $total = $hits + $misses;
        
        return [
            'hits' => $hits,
            'misses' => $misses,
            'rate' => $total ? ($hits / $total) * 100 : 0
        ];
    }
}
