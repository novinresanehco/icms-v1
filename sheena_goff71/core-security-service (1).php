<?php

namespace App\Core\Security;

class CoreSecurityManager implements SecurityManagerInterface 
{
    private ValidationService $validator;
    private EncryptionService $encryption;
    private AuditLogger $auditLogger;
    private AccessControl $accessControl;
    private SecurityConfig $config;
    private MetricsCollector $metrics;

    public function __construct(
        ValidationService $validator,
        EncryptionService $encryption,
        AuditLogger $auditLogger,
        AccessControl $accessControl,
        SecurityConfig $config,
        MetricsCollector $metrics
    ) {
        $this->validator = $validator;
        $this->encryption = $encryption;
        $this->auditLogger = $auditLogger;
        $this->accessControl = $accessControl;
        $this->config = $config;
        $this->metrics = $metrics;
    }

    public function executeCriticalOperation(
        CriticalOperation $operation,
        SecurityContext $context
    ): OperationResult {
        DB::beginTransaction();
        $startTime = microtime(true);
        
        try {
            $this->validateOperation($operation, $context);
            $result = $this->executeWithProtection($operation, $context);
            $this->verifyResult($result);
            
            DB::commit();
            $this->logSuccess($operation, $context, $result);
            
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFailure($operation, $context, $e);
            throw new SecurityException(
                'Critical operation failed: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        } finally {
            $this->recordMetrics($operation, microtime(true) - $startTime);
        }
    }

    private function validateOperation(
        CriticalOperation $operation, 
        SecurityContext $context
    ): void {
        $this->validator->validateInput(
            $operation->getData(),
            $operation->getValidationRules()
        );

        if (!$this->accessControl->hasPermission($context, $operation->getRequiredPermissions())) {
            $this->auditLogger->logUnauthorizedAccess($context, $operation);
            throw new UnauthorizedException();
        }

        if (!$this->accessControl->checkRateLimit($context, $operation->getRateLimitKey())) {
            $this->auditLogger->logRateLimitExceeded($context, $operation);
            throw new RateLimitException();
        }
    }

    private function executeWithProtection(
        CriticalOperation $operation,
        SecurityContext $context
    ): OperationResult {
        $monitor = new OperationMonitor($operation, $context);
        
        try {
            $result = $monitor->execute(function() use ($operation) {
                return $operation->execute();
            });

            if (!$result->isValid()) {
                throw new OperationException('Invalid operation result');
            }

            return $result;
            
        } catch (\Exception $e) {
            $monitor->recordFailure($e);
            throw $e;
        }
    }

    private function handleFailure(
        CriticalOperation $operation,
        SecurityContext $context,
        \Exception $e
    ): void {
        $this->auditLogger->logOperationFailure(
            $operation,
            $context,
            $e,
            [
                'stack_trace' => $e->getTraceAsString(),
                'input_data' => $operation->getData(),
                'system_state' => $this->captureSystemState()
            ]
        );

        $this->metrics->incrementFailureCount(
            $operation->getType(),
            $e->getCode()
        );
    }
}

abstract class BaseService
{
    protected Repository $repository;
    protected CacheManager $cache;
    protected ValidationService $validator;
    protected MetricsCollector $metrics;
    protected AuditLogger $logger;

    protected function executeInTransaction(callable $operation)
    {
        DB::beginTransaction();
        
        try {
            $result = $operation();
            DB::commit();
            $this->logger->info('Operation completed successfully');
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->logger->error('Operation failed', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    protected function validateData(array $data, array $rules): array
    {
        return $this->validator->validate($data, $rules);
    }

    protected function cache(string $key, callable $callback, $ttl = null)
    {
        return $this->cache->remember($key, $callback, $ttl);
    }

    protected function recordMetrics(string $operation, array $metrics): void
    {
        $this->metrics->record($operation, $metrics);
    }
}

abstract class Repository
{
    protected Model $model;
    protected CacheManager $cache;
    protected ValidationService $validator;

    public function find(int $id): ?Model
    {
        return $this->cache->remember(
            $this->getCacheKey('find', $id),
            fn() => $this->model->find($id)
        );
    }

    public function create(array $data): Model
    {
        $validated = $this->validator->validate($data, $this->rules());
        
        return DB::transaction(function() use ($validated) {
            $model = $this->model->create($validated);
            $this->cache->put(
                $this->getCacheKey('find', $model->id),
                $model
            );
            return $model;
        });
    }

    abstract protected function rules(): array;
    abstract protected function getCacheKey(string $operation, ...$params): string;
}
