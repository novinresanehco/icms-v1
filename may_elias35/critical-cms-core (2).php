<?php

namespace App\Core\CMS;

use App\Core\Security\SecurityManagerInterface;
use App\Core\CMS\Content\ContentRepository;
use App\Core\CMS\Media\MediaManager;
use App\Core\Cache\CacheManager;
use App\Core\Monitoring\MonitoringService;
use App\Core\Audit\AuditLogger;

class CMSCore implements CMSInterface
{
    private SecurityManagerInterface $security;
    private ContentRepository $content;
    private MediaManager $media;
    private CacheManager $cache;
    private MonitoringService $monitor;
    private AuditLogger $audit;

    private const MAX_EXECUTION_TIME = 5000; // ms
    private const CACHE_TTL = 3600; // 1 hour
    private const MAX_RETRIES = 3;

    public function __construct(
        SecurityManagerInterface $security,
        ContentRepository $content,
        MediaManager $media,
        CacheManager $cache,
        MonitoringService $monitor,
        AuditLogger $audit
    ) {
        $this->security = $security;
        $this->content = $content;
        $this->media = $media;
        $this->cache = $cache;
        $this->monitor = $monitor;
        $this->audit = $audit;
    }

    public function executeOperation(CMSOperation $operation): OperationResult 
    {
        $operationId = $this->monitor->startOperation();
        $startTime = microtime(true);

        DB::beginTransaction();

        try {
            // System validation
            $this->validateSystemState();
            
            // Security validation
            $this->security->validateOperation($operation);
            
            // Execute operation
            $result = $this->executeProtectedOperation($operation);
            
            // Validate result
            $this->validateOperationResult($result);
            
            // Commit transaction
            DB::commit();
            
            // Update cache
            $this->updateCacheData($result);
            
            // Log success
            $this->logSuccess($operation, $startTime);
            
            return $result;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleOperationFailure($e, $operation);
            throw $e;

        } finally {
            $this->monitor->endOperation($operationId);
        }
    }

    private function validateSystemState(): void 
    {
        if (!$this->monitor->isSystemHealthy()) {
            throw new SystemStateException('System health check failed');
        }

        if (!$this->cache->isOperational()) {
            throw new CacheException('Cache system not operational');
        }

        if ($this->monitor->isHighLoad()) {
            throw new PerformanceException('System under high load');
        }
    }

    private function executeProtectedOperation(CMSOperation $operation): OperationResult
    {
        $retryCount = 0;
        $lastError = null;

        while ($retryCount < self::MAX_RETRIES) {
            try {
                return $this->doExecuteOperation($operation);
            } catch (RetryableException $e) {
                $lastError = $e;
                $retryCount++;
                $this->handleRetry($operation, $retryCount);
            }
        }

        throw new OperationFailedException(
            'Operation failed after max retries',
            previous: $lastError
        );
    }

    private function doExecuteOperation(CMSOperation $operation): OperationResult
    {
        $context = $this->createOperationContext();

        if ($operation instanceof ContentOperation) {
            return $this->content->execute($operation, $context);
        }

        if ($operation instanceof MediaOperation) {
            return $this->media->execute($operation, $context);
        }

        throw new InvalidOperationException('Unknown operation type');
    }

    private function validateOperationResult(OperationResult $result): void
    {
        if (!$result->isValid()) {
            throw new ValidationException('Invalid operation result');
        }

        if (!$this->validateResultIntegrity($result)) {
            throw new IntegrityException('Result integrity check failed');
        }

        if (!$this->security->validateResult($result)) {
            throw new SecurityException('Security validation failed');
        }
    }

    private function updateCacheData(OperationResult $result): void
    {
        $cacheKeys = $result->getCacheKeys();
        
        foreach ($cacheKeys as $key) {
            $this->cache->invalidate($key);
        }

        $this->cache->store(
            $result->getCacheKey(),
            $result->getCacheData(),
            self::CACHE_TTL
        );
    }

    private function createOperationContext(): OperationContext
    {
        return new OperationContext(
            security: $this->security,
            monitor: $this->monitor,
            cache: $this->cache
        );
    }

    private function validateResultIntegrity(OperationResult $result): bool
    {
        return $result->getChecksum() === hash(
            'sha256',
            serialize($result->getData())
        );
    }

    private function handleOperationFailure(\Exception $e, CMSOperation $operation): void
    {
        // Log failure
        $this->audit->logFailure(
            'cms_operation_failed',
            [
                'operation' => $operation->getId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]
        );

        // Update monitoring
        $this->monitor->recordFailure($operation);

        // Clear related cache
        $this->cache->invalidateByTags($operation->getCacheTags());

        // Execute failure protocols
        if ($e instanceof SecurityException) {
            $this->security->handleSecurityFailure($e);
        }
    }

    private function handleRetry(CMSOperation $operation, int $retryCount): void
    {
        $this->audit->logRetry($operation, $retryCount);
        $this->monitor->recordRetry($operation);
        
        // Exponential backoff
        usleep(100000 * pow(2, $retryCount)); // 100ms, 200ms, 400ms
    }

    private function logSuccess(CMSOperation $operation, float $startTime): void
    {
        $duration = (microtime(true) - $startTime) * 1000;

        $this->audit->logSuccess(
            'cms_operation_completed',
            [
                'operation' => $operation->getId(),
                'duration' => $duration
            ]
        );

        $this->monitor->recordSuccess($operation, $duration);
    }
}
