<?php

namespace App\Core\Data;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Core\Security\SecurityContext;
use App\Core\Cache\CacheManager;
use App\Core\Validation\ValidationService;

class DataAccessManager implements DataAccessInterface 
{
    private SecurityManager $security;
    private CacheManager $cache;
    private ValidationService $validator;
    private MetricsCollector $metrics;
    
    public function executeDataOperation(callable $operation, array $context): mixed 
    {
        $traceId = $this->metrics->startOperation('data_access');
        
        try {
            DB::beginTransaction();
            
            $this->validateContext($context);
            $this->checkPermissions($context);
            
            $result = $this->executeWithCache($operation, $context);
            
            $this->validateResult($result);
            $this->updateMetrics($result, $traceId);
            
            DB::commit();
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleError($e, $traceId);
            throw $e;
        } finally {
            $this->metrics->endOperation($traceId);
        }
    }
    
    private function validateContext(array $context): void 
    {
        if (!$this->validator->validateDataContext($context)) {
            throw new DataValidationException('Invalid data context');
        }
    }
    
    private function checkPermissions(array $context): void 
    {
        if (!$this->security->checkDataAccess($context)) {
            throw new DataAccessDeniedException();
        }
    }
    
    private function executeWithCache(callable $operation, array $context): mixed 
    {
        $cacheKey = $this->generateCacheKey($context);
        
        if ($this->shouldUseCache($context)) {
            return $this->cache->remember($cacheKey, function() use ($operation) {
                return $this->executeWithTracking($operation);
            });
        }
        
        return $this->executeWithTracking($operation);
    }
    
    private function executeWithTracking(callable $operation): mixed 
    {
        $startTime = microtime(true);
        $result = $operation();
        $duration = microtime(true) - $startTime;
        
        $this->metrics->recordDuration('data_operation', $duration);
        
        return $result;
    }
    
    private function validateResult($result): void 
    {
        if (!$this->validator->validateDataResult($result)) {
            throw new DataValidationException('Invalid operation result');
        }
    }
    
    private function updateMetrics($result, string $traceId): void 
    {
        $this->metrics->recordResult($result, [
            'trace_id' => $traceId,
            'result_type' => gettype($result),
            'operation_status' => 'success'
        ]);
    }
    
    private function handleError(\Exception $e, string $traceId): void 
    {
        $this->metrics->recordError($e, [
            'trace_id' => $traceId,
            'error_type' => get_class($e)
        ]);
        
        $this->notifyError($e, $traceId);
    }
    
    private function generateCacheKey(array $context): string 
    {
        return sprintf(
            'data_%s_%s',
            $context['type'],
            md5(serialize($context['params']))
        );
    }
    
    private function shouldUseCache(array $context): bool 
    {
        return isset($context['cache_enabled']) && 
               $context['cache_enabled'] === true && 
               !isset($context['skip_cache']);
    }
    
    public function query(string $query, array $params = [], array $context = []): mixed 
    {
        return $this->executeDataOperation(
            fn() => DB::select($query, $params),
            array_merge($context, ['type' => 'query'])
        );
    }
    
    public function insert(string $table, array $data, array $context = []): int 
    {
        return $this->executeDataOperation(
            fn() => DB::table($table)->insertGetId($data),
            array_merge($context, ['type' => 'insert'])
        );
    }
    
    public function update(string $table, array $data, array $conditions, array $context = []): int 
    {
        return $this->executeDataOperation(
            fn() => DB::table($table)->where($conditions)->update($data),
            array_merge($context, ['type' => 'update'])
        );
    }
    
    public function delete(string $table, array $conditions, array $context = []): int 
    {
        return $this->executeDataOperation(
            fn() => DB::table($table)->where($conditions)->delete(),
            array_merge($context, ['type' => 'delete'])
        );
    }
}
