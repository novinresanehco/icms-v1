<?php

namespace App\Core\Integration;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Cache\CacheManagerInterface;
use App\Core\Infrastructure\FailoverService;
use App\Core\Monitoring\HealthCheckService;
use App\Core\Audit\AuditLogger;

class SystemIntegrationService implements IntegrationInterface 
{
    private SecurityManagerInterface $security;
    private CacheManagerInterface $cache;
    private FailoverService $failover;
    private HealthCheckService $health;
    private AuditLogger $audit;

    private const MAX_RETRY_ATTEMPTS = 3;
    private const OPERATION_TIMEOUT = 5000; // ms
    private const CACHE_TTL = 3600; // 1 hour

    public function __construct(
        SecurityManagerInterface $security,
        CacheManagerInterface $cache,
        FailoverService $failover,
        HealthCheckService $health,
        AuditLogger $audit
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->failover = $failover;
        $this->health = $health;
        $this->audit = $audit;
    }

    public function executeIntegration(IntegrationOperation $operation): OperationResult 
    {
        DB::beginTransaction();

        try {
            // Validate system state
            $this->validateSystemState();

            // Security validation
            $this->security->validateOperation($operation);

            // Check cache
            if ($cached = $this->checkCache($operation)) {
                return $cached;
            }

            // Execute integration
            $result = $this->executeWithFailover($operation);

            // Validate result
            $this->validateResult($result);

            // Update cache
            $this->updateCache($operation, $result);

            DB::commit();

            return $result;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFailure($e, $operation);
            throw $e;
        }
    }

    private function validateSystemState(): void 
    {
        if (!$this->health->isSystemHealthy()) {
            throw new SystemStateException('System health check failed');
        }

        if (!$this->failover->isReady()) {
            throw new FailoverException('Failover system not ready');
        }

        if ($this->health->isOverloaded()) {
            throw new SystemLoadException('System overload detected');
        }
    }

    private function checkCache(IntegrationOperation $operation): ?OperationResult
    {
        $cacheKey = $this->generateCacheKey($operation);
        
        if ($cached = $this->cache->get($cacheKey)) {
            if ($this->validateCachedResult($cached)) {
                $this->audit->logCacheHit($operation);
                return $cached;
            }
            
            $this->cache->delete($cacheKey);
        }

        return null;
    }

    private function executeWithFailover(IntegrationOperation $operation): OperationResult
    {
        $attempts = 0;
        $lastError = null;

        while ($attempts < self::MAX_RETRY_ATTEMPTS) {
            try {
                return $this->doExecute($operation);
            } catch (RetryableException $e) {
                $lastError = $e;
                $attempts++;
                
                if ($attempts >= self::MAX_RETRY_ATTEMPTS) {
                    $this->failover->activate($operation);
                    return $this->executeFailover($operation);
                }
                
                $this->handleRetry($attempts, $e);
            }
        }

        throw new IntegrationException(
            'Integration failed after max retries',
            previous: $lastError
        );
    }

    private function doExecute(IntegrationOperation $operation): OperationResult
    {
        $startTime = microtime(true);
        
        try {
            $result = $operation->execute($this->createContext());

            if ((microtime(true) - $startTime) * 1000 > self::OPERATION_TIMEOUT) {
                throw new TimeoutException('Operation timeout exceeded');
            }

            return $result;

        } catch (\Exception $e) {
            $this->handleExecutionError($e, $operation);
            throw $e;
        }
    }

    private function executeFailover(IntegrationOperation $operation): OperationResult
    {
        $this->audit->logFailoverActivation($operation);
        
        try {
            return $this->failover->execute($operation);
        } catch (\Exception $e) {
            $this->handleFailoverError($e, $operation);
            throw new CriticalException('Failover execution failed', previous: $e);
        }
    }

    private function validateResult(OperationResult $result): void
    {
        if (!$result->isValid()) {
            throw new ValidationException('Invalid operation result');
        }

        if (!$this->security->validateResult($result)) {
            throw new SecurityException('Security validation failed');
        }

        if (!$this->validateResultIntegrity($result)) {
            throw new IntegrityException('Result integrity check failed');
        }
    }

    private function validateResultIntegrity(OperationResult $result): bool
    {
        return hash_equals(
            $result->getChecksum(),
            hash('sha256', serialize($result->getData()))
        );
    }

    private function validateCachedResult(OperationResult $result): bool
    {
        return $result->isValid() &&
               $this->security->validateCachedResult($result) &&
               !$result->isExpired();
    }

    private function updateCache(IntegrationOperation $operation, OperationResult $result): void
    {
        $this->cache->set(
            $this->generateCacheKey($operation),
            $result,
            self::CACHE_TTL
        );
    }

    private function generateCacheKey(IntegrationOperation $operation): string
    {
        return hash('sha256', serialize([
            'operation' => $operation->getId(),
            'params' => $operation->getParameters(),
            'context' => $operation->getContext()
        ]));
    }

    private function createContext(): IntegrationContext
    {
        return new IntegrationContext(
            security: $this->security,
            cache: $this->cache,
            health: $this->health
        );
    }

    private function handleRetry(int $attempt, \Exception $e): void
    {
        $this->audit->logRetry($attempt, [
            'error' => $e->getMessage(),
            'timestamp' => now()
        ]);

        usleep(100000 * pow(2, $attempt));
    }

    private function handleExecutionError(\Exception $e, IntegrationOperation $operation): void
    {
        $this->audit->logError('execution_error', [
            'operation' => $operation->getId(),
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        if ($e instanceof SecurityException) {
            $this->security->handleSecurityFailure($e);
        }
    }

    private function handleFailoverError(\Exception $e, IntegrationOperation $operation): void
    {
        $this->audit->logCritical('failover_error', [
            'operation' => $operation->getId(),
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        $this->failover->handleFailure($e);
    }

    private function handleFailure(\Exception $e, IntegrationOperation $operation): void
    {
        $this->audit->logCritical('integration_failure', [
            'operation' => $operation->getId(),
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        $this->cache->invalidate($this->generateCacheKey($operation));

        if ($e instanceof SystemStateException) {
            $this->health->initiateRecovery();
        }

        if ($e instanceof SecurityException) {
            $this->security->handleSecurityFailure($e);
        }
    }
}
