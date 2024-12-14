<?php
namespace App\Core;

class CriticalCmsController {
    private SecurityGuard $security;
    private ContentManager $content;
    private ValidationEngine $validator;
    private AuditTracker $audit;

    public function executeOperation(string $operation, array $context): mixed 
    {
        DB::beginTransaction();
        
        try {
            // Pre-execution validation
            $this->security->validateOperation($operation, $context);
            $this->validator->validateContext($operation, $context);
            $this->audit->beginOperation($operation, $context);
            
            // Execute with monitoring
            $result = match($operation) {
                'content.create' => $this->content->createContent($context),
                'content.update' => $this->content->updateContent($context),
                'content.delete' => $this->content->deleteContent($context),
                default => throw new InvalidOperationException()
            };
            
            // Validate result
            $this->validator->validateResult($result);
            $this->audit->recordSuccess($operation, $result);
            
            DB::commit();
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFailure($e, $operation, $context);
            throw $e;
        }
    }

    private function handleFailure(\Exception $e, string $operation, array $context): void 
    {
        $this->audit->recordFailure($operation, $e, $context);
        $this->security->handleSecurityEvent($e);
    }
}

class SecurityGuard {
    private AccessControl $access;
    private SecurityMonitor $monitor;
    private ConfigManager $config;

    public function validateOperation(string $operation, array $context): void 
    {
        // Validate access permissions
        if (!$this->access->hasPermission($operation, $context)) {
            throw new SecurityException('Access denied');
        }

        // Check rate limits
        if (!$this->validateRateLimits($operation, $context)) {
            throw new SecurityException('Rate limit exceeded');
        }

        // Record security event
        $this->monitor->recordAccess($operation, $context);
    }

    public function handleSecurityEvent(\Exception $e): void 
    {
        $this->monitor->recordSecurityEvent($e);
        
        if ($e instanceof SecurityException) {
            $this->handleSecurityException($e);
        }
    }

    private function validateRateLimits(string $operation, array $context): bool 
    {
        $limits = $this->config->get('security.rate_limits');
        return $this->monitor->checkRateLimits($operation, $context, $limits);
    }
}

class ContentManager {
    private Repository $repository;
    private CacheManager $cache;
    private ValidationEngine $validator;

    public function createContent(array $context): Content 
    {
        $this->validator->validateCreate($context);
        
        $content = $this->repository->create($context);
        $this->cache->invalidateGroup('content');
        
        return $content;
    }

    public function updateContent(array $context): Content 
    {
        $this->validator->validateUpdate($context);
        
        $content = $this->repository->update(
            $context['id'],
            $this->prepareUpdateData($context)
        );
        
        $this->cache->invalidate("content.{$context['id']}");
        
        return $content;
    }

    private function prepareUpdateData(array $context): array 
    {
        return array_filter($context, function($key) {
            return !in_array($key, ['id', 'created_at']);
        }, ARRAY_FILTER_USE_KEY);
    }
}

class ValidationEngine {
    private RuleEngine $rules;
    private SecurityValidator $security;

    public function validateContext(string $operation, array $context): void 
    {
        $rules = $this->rules->getOperationRules($operation);
        
        foreach ($rules as $rule) {
            if (!$rule->validate($context)) {
                throw new ValidationException($rule->getMessage());
            }
        }
    }

    public function validateResult($result): void 
    {
        if (!$this->isValidResult($result)) {
            throw new ValidationException('Invalid operation result');
        }
    }

    private function isValidResult($result): bool 
    {
        return $result instanceof Content || is_bool($result);
    }
}

class AuditTracker {
    private LogStorage $storage;
    private MetricsCollector $metrics;

    public function beginOperation(string $operation, array $context): void 
    {
        $this->metrics->beginTracking($operation, [
            'timestamp' => microtime(true),
            'context' => $this->sanitizeContext($context)
        ]);
    }

    public function recordSuccess(string $operation, $result): void 
    {
        $this->storage->log([
            'operation' => $operation,
            'status' => 'success',
            'timestamp' => microtime(true),
            'metrics' => $this->metrics->getOperationMetrics($operation)
        ]);
    }

    private function sanitizeContext(array $context): array 
    {
        return array_filter($context, function($key) {
            return !in_array($key, ['password', 'token']);
        }, ARRAY_FILTER_USE_KEY);
    }
}

interface AccessControl {
    public function hasPermission(string $operation, array $context): bool;
}

interface SecurityMonitor {
    public function recordAccess(string $operation, array $context): void;
    public function recordSecurityEvent(\Exception $e): void;
    public function checkRateLimits(string $operation, array $context, array $limits): bool;
}

interface CacheManager {
    public function invalidate(string $key): void;
    public function invalidateGroup(string $group): void;
}

interface Repository {
    public function create(array $data): Content;
    public function update(int $id, array $data): Content;
    public function delete(int $id): bool;
}

interface LogStorage {
    public function log(array $data): void;
}

interface MetricsCollector {
    public function beginTracking(string $operation, array $context): void;
    public function getOperationMetrics(string $operation): array;
}

interface ConfigManager {
    public function get(string $key): mixed;
}
