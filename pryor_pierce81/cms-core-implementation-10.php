<?php

namespace App\Core;

class ContentRepository implements RepositoryInterface 
{
    private SecurityManager $security;
    private CacheManager $cache;
    private Database $db;
    private Validator $validator;
    private Monitor $monitor;

    public function store(array $data): Result
    {
        return $this->security->executeProtected(function() use ($data) {
            DB::beginTransaction();
            
            try {
                $validated = $this->validator->validate($data);
                $record = $this->db->store($validated);
                $this->cache->invalidate($record->key);
                $this->monitor->recordOperation('store', $record);
                
                DB::commit();
                return new Result($record);
            } catch (\Exception $e) {
                DB::rollBack();
                $this->monitor->recordFailure('store', $e);
                throw $e;
            }
        });
    }

    public function find(string $id): Result
    {
        return $this->cache->remember($id, function() use ($id) {
            return $this->security->executeProtected(function() use ($id) {
                $record = $this->db->find($id);
                $this->monitor->recordOperation('find', $record);
                return new Result($record);
            });
        });
    }
}

class SecurityManager
{
    private AuthManager $auth;
    private AuditLogger $audit;
    private Monitor $monitor;

    public function executeProtected(callable $operation): mixed
    {
        $this->auth->validateAccess();
        $startTime = microtime(true);
        
        try {
            $result = $operation();
            
            $this->monitor->recordMetrics([
                'operation' => get_class($operation),
                'duration' => microtime(true) - $startTime,
                'status' => 'success'
            ]);
            
            $this->audit->logSuccess($operation);
            
            return $result;
        } catch (\Exception $e) {
            $this->monitor->recordMetrics([
                'operation' => get_class($operation),
                'duration' => microtime(true) - $startTime,
                'status' => 'failure',
                'error' => $e->getMessage()
            ]);
            
            $this->audit->logFailure($operation, $e);
            throw $e;
        }
    }
}

class CacheManager
{
    private Cache $cache;
    private Monitor $monitor;

    public function remember(string $key, callable $callback): mixed
    {
        $startTime = microtime(true);
        
        if ($value = $this->cache->get($key)) {
            $this->monitor->recordMetrics([
                'operation' => 'cache_hit',
                'key' => $key,
                'duration' => microtime(true) - $startTime
            ]);
            return $value;
        }

        $value = $callback();
        $this->cache->put($key, $value);
        
        $this->monitor->recordMetrics([
            'operation' => 'cache_miss',
            'key' => $key,
            'duration' => microtime(true) - $startTime
        ]);
        
        return $value;
    }
}

class Monitor
{
    private MetricsCollector $metrics;
    private AlertManager $alerts;
    private Logger $logger;

    public function recordOperation(string $type, $data): void
    {
        $metrics = [
            'type' => $type,
            'timestamp' => microtime(true),
            'memory' => memory_get_usage(true),
            'data_size' => strlen(serialize($data))
        ];
        
        $this->metrics->record($metrics);
        
        if ($this->shouldAlert($metrics)) {
            $this->alerts->trigger($type, $metrics);
        }
        
        $this->logger->info("Operation recorded", $metrics);
    }

    public function recordFailure(string $type, \Exception $e): void
    {
        $metrics = [
            'type' => $type,
            'error' => $e->getMessage(),
            'timestamp' => microtime(true),
            'trace' => $e->getTraceAsString()
        ];
        
        $this->metrics->recordFailure($metrics);
        $this->alerts->triggerFailure($type, $metrics);
        $this->logger->error("Operation failed", $metrics);
    }

    private function shouldAlert(array $metrics): bool
    {
        return $metrics['memory'] > 100 * 1024 * 1024 || // 100MB
               $metrics['data_size'] > 10 * 1024 * 1024; // 10MB
    }
}

class Validator 
{
    private array $rules = [
        'title' => 'required|string|max:200',
        'content' => 'required|string|max:65535',
        'status' => 'required|in:draft,published',
        'author_id' => 'required|exists:users,id'
    ];

    public function validate(array $data): array
    {
        $validated = [];
        
        foreach ($this->rules as $field => $rules) {
            if (!isset($data[$field]) && str_contains($rules, 'required')) {
                throw new ValidationException("Field $field is required");
            }
            
            $validated[$field] = $this->validateField(
                $data[$field] ?? null,
                $rules
            );
        }
        
        return $validated;
    }

    private function validateField($value, string $rules): mixed
    {
        foreach (explode('|', $rules) as $rule) {
            if (!$this->checkRule($value, $rule)) {
                throw new ValidationException("Validation failed for rule: $rule");
            }
        }
        
        return $value;
    }

    private function checkRule($value, string $rule): bool
    {
        return match($rule) {
            'required' => !empty($value),
            'string' => is_string($value),
            'max:200' => strlen($value) <= 200,
            'max:65535' => strlen($value) <= 65535,
            default => true
        };
    }
}
