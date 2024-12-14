<?php

namespace App\Core\Services;

use App\Core\Security\SecurityManager;
use App\Core\Validation\ValidationService;
use App\Core\Monitoring\MonitoringService;
use App\Core\Logging\AuditLogger;
use App\Core\Cache\CacheManager;
use Illuminate\Support\Facades\DB;

abstract class CriticalBaseService
{
    protected SecurityManager $security;
    protected ValidationService $validator;
    protected MonitoringService $monitor;
    protected AuditLogger $logger;
    protected CacheManager $cache;

    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        MonitoringService $monitor,
        AuditLogger $logger,
        CacheManager $cache
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->monitor = $monitor;
        $this->logger = $logger;
        $this->cache = $cache;
    }

    /**
     * Critical operation execution with full protection
     */
    protected function executeCritical(string $operation, callable $action, array $context = []): mixed
    {
        $operationId = $this->monitor->startOperation($operation);
        
        try {
            // Pre-execution validation
            $this->validator->validateOperation($operation, $context);
            
            // Security check
            $this->security->validateAccess($operation, $context);
            
            DB::beginTransaction();
            
            // Execute with monitoring
            $result = $this->monitor->track($operationId, $action);
            
            // Validate result
            $this->validator->validateResult($result);
            
            DB::commit();
            
            // Log success
            $this->logger->logSuccess($operation, $context, $result);
            
            return $result;
            
        } catch (\Throwable $e) {
            DB::rollBack();
            
            // Log failure
            $this->logger->logFailure($operation, $e, $context);
            
            throw $e;
        } finally {
            $this->monitor->endOperation($operationId);
        }
    }

    /**
     * Data retrieval with caching and validation
     */
    protected function retrieveData(string $key, callable $loader): mixed
    {
        return $this->cache->remember($key, function() use ($loader, $key) {
            $data = $loader();
            
            // Validate loaded data
            $this->validator->validateData($data);
            
            // Log access
            $this->logger->logDataAccess($key);
            
            return $data;
        });
    }

    /**
     * Data storage with validation and security
     */
    protected function storeData(string $key, $data, array $context = []): void
    {
        $this->executeCritical('data:store', function() use ($key, $data) {
            // Validate data before storage
            $this->validator->validateData($data);
            
            // Store with encryption if needed
            $this->security->storeSecurely($key, $data);
            
            // Invalidate cache
            $this->cache->forget($key);
        }, $context);
    }

    /**
     * Security context preparation
     */
    protected function prepareContext(array $data = []): array
    {
        return array_merge([
            'timestamp' => now(),
            'service' => static::class,
        ], $data);
    }

    /**
     * Resource cleanup
     */
    protected function cleanup(string $operation): void
    {
        try {
            $this->cache->cleanup($operation);
            $this->monitor->cleanup($operation);
        } catch (\Throwable $e) {
            $this->logger->logError('Cleanup failed', ['operation' => $operation, 'error' => $e]);
        }
    }
}
