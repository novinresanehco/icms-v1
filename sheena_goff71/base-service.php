namespace App\Core\Services;

use App\Core\Security\SecurityManager;
use Illuminate\Support\Facades\{DB, Event, Log};
use Illuminate\Database\Eloquent\Model;

abstract class BaseService implements ServiceInterface
{
    protected SecurityManager $security;
    protected RepositoryInterface $repository;
    protected ValidationService $validator;
    protected EventDispatcher $events;
    protected CacheManager $cache;
    protected AuditLogger $logger;

    public function __construct(
        SecurityManager $security,
        RepositoryInterface $repository,
        ValidationService $validator,
        EventDispatcher $events,
        CacheManager $cache,
        AuditLogger $logger
    ) {
        $this->security = $security;
        $this->repository = $repository;
        $this->validator = $validator;
        $this->events = $events;
        $this->cache = $cache;
        $this->logger = $logger;
    }

    protected function executeOperation(ServiceOperation $operation, SecurityContext $context, callable $action): mixed
    {
        return $this->security->executeCriticalOperation(
            $operation,
            $context,
            function() use ($operation, $action) {
                $startTime = microtime(true);
                
                try {
                    DB::beginTransaction();
                    
                    // Pre-execution validation
                    $this->validateOperation($operation);
                    
                    // Execute the operation
                    $result = $action();
                    
                    // Post-execution verification
                    $this->verifyResult($result, $operation);
                    
                    // Commit transaction
                    DB::commit();
                    
                    // Log success
                    $this->logSuccess($operation, $result);
                    
                    // Dispatch events
                    $this->dispatchEvents($operation, $result);
                    
                    return $result;
                    
                } catch (\Throwable $e) {
                    DB::rollBack();
                    $this->handleFailure($operation, $e);
                    throw $e;
                } finally {
                    $this->recordMetrics($operation, microtime(true) - $startTime);
                }
            }
        );
    }

    protected function validateOperation(ServiceOperation $operation): void
    {
        // Validate operation parameters
        $this->validator->validateOperation($operation);
        
        // Check business rules
        if (!$this->checkBusinessRules($operation)) {
            throw new BusinessRuleException('Operation violates business rules');
        }
        
        // Verify system state
        if (!$this->verifySystemState()) {
            throw new SystemStateException('System is not in valid state for operation');
        }
    }

    protected function verifyResult($result, ServiceOperation $operation): void
    {
        // Validate result data
        $this->validator->validateResult($result);
        
        // Check result integrity
        if (!$this->verifyResultIntegrity($result)) {
            throw new IntegrityException('Result integrity check failed');
        }
        
        // Verify business rules
        if (!$this->verifyBusinessRules($result)) {
            throw new BusinessRuleException('Result violates business rules');
        }
    }

    protected function handleFailure(ServiceOperation $operation, \Throwable $e): void
    {
        // Log detailed failure information
        $this->logger->logOperationFailure(
            $operation,
            $e,
            [
                'stack_trace' => $e->getTraceAsString(),
                'input_data' => $operation->getData(),
                'system_state' => $this->captureSystemState()
            ]
        );

        // Clear related caches
        $this->invalidateRelatedCaches($operation);
        
        // Execute recovery procedures if needed
        $this->executeFailureRecovery($operation, $e);
        
        // Update metrics
        $this->updateFailureMetrics($operation, $e);
    }

    protected function dispatchEvents(ServiceOperation $operation, $result): void
    {
        $events = $operation->getEvents($result);
        
        foreach ($events as $event) {
            try {
                $this->events->dispatch($event);
            } catch (\Exception $e) {
                // Log event dispatch failure but don't throw
                $this->logger->logEventDispatchFailure($event, $e);
            }
        }
    }

    protected function logSuccess(ServiceOperation $operation, $result): void
    {
        $this->logger->logOperationSuccess(
            $operation,
            [
                'result' => $this->sanitizeForLogging($result),
                'execution_time' => microtime(true) - $operation->getStartTime(),
                'memory_usage' => memory_get_peak_usage(true)
            ]
        );
    }

    protected function invalidateRelatedCaches(ServiceOperation $operation): void
    {
        foreach ($operation->getCacheKeys() as $key) {
            $this->cache->forget($key);
        }
        
        foreach ($operation->getCacheTags() as $tag) {
            $this->cache->tags($tag)->flush();
        }
    }

    protected function executeFailureRecovery(ServiceOperation $operation, \Throwable $e): void
    {
        try {
            // Execute operation-specific recovery
            $operation->executeRecovery($e);
            
            // Execute system-level recovery if needed
            if ($this->requiresSystemRecovery($e)) {
                $this->executeSystemRecovery();
            }
        } catch (\Exception $recoveryError) {
            // Log recovery failure but don't throw
            $this->logger->logRecoveryFailure($operation, $recoveryError);
        }
    }

    protected function updateFailureMetrics(ServiceOperation $operation, \Throwable $e): void
    {
        $metrics = [
            'operation_type' => get_class($operation),
            'error_type' => get_class($e),
            'error_code' => $e->getCode(),
            'timestamp' => time()
        ];
        
        $this->logger->recordMetrics('operation_failure', $metrics);
    }

    protected function sanitizeForLogging($data): mixed
    {
        if ($data instanceof Model) {
            return [
                'id' => $data->id,
                'type' => get_class($data),
                'attributes' => $data->attributesToArray()
            ];
        }
        
        if (is_array($data)) {
            return array_map([$this, 'sanitizeForLogging'], $data);
        }
        
        return $data;
    }

    protected function captureSystemState(): array
    {
        return [
            'memory_usage' => memory_get_peak_usage(true),
            'system_load' => sys_getloadavg(),
            'database_connections' => DB::connection()->getDatabaseName()
        ];
    }
}
