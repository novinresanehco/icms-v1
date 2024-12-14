<?php

namespace App\Core\Service;

use App\Core\Security\SecurityManager;
use App\Core\Audit\AuditSystem;
use App\Core\Cache\CacheManager;
use App\Core\Validation\ValidationSystem;
use App\Core\Repository\RepositoryInterface;
use Illuminate\Support\Facades\DB;

class ServiceManager implements ServiceInterface
{
    private SecurityManager $security;
    private AuditSystem $audit;
    private CacheManager $cache;
    private ValidationSystem $validator;
    private array $config;
    private array $activeTransactions = [];

    public function __construct(
        SecurityManager $security,
        AuditSystem $audit,
        CacheManager $cache,
        ValidationSystem $validator,
        array $config
    ) {
        $this->security = $security;
        $this->audit = $audit;
        $this->cache = $cache;
        $this->validator = $validator;
        $this->config = $config;
    }

    public function execute(string $service, string $method, array $params): mixed
    {
        $transactionId = $this->generateTransactionId();
        
        try {
            // Pre-execution validation
            $this->validateServiceCall($service, $method, $params);
            
            // Start transaction monitoring
            $this->beginTransaction($transactionId, $service, $method);
            
            // Execute service method
            $result = $this->executeServiceMethod($service, $method, $params);
            
            // Commit transaction
            $this->commitTransaction($transactionId);
            
            return $result;
            
        } catch (\Throwable $e) {
            $this->rollbackTransaction($transactionId);
            $this->handleServiceFailure($e, $service, $method, $params);
            throw $e;
        }
    }

    private function validateServiceCall(string $service, string $method, array $params): void
    {
        // Security validation
        $this->security->validateOperation("service.{$service}.{$method}", [
            'service' => $service,
            'method' => $method,
            'params' => $params
        ]);

        // Parameter validation
        $rules = $this->config['validation_rules'][$service][$method] ?? [];
        $this->validator->validate($params, $rules);

        // Rate limiting
        $this->checkRateLimit($service, $method);
    }

    private function beginTransaction(string $transactionId, string $service, string $method): void
    {
        DB::beginTransaction();
        
        $this->activeTransactions[$transactionId] = [
            'service' => $service,
            'method' => $method,
            'start_time' => microtime(true),
            'isolation_level' => $this->getIsolationLevel($service, $method)
        ];

        $this->setTransactionIsolationLevel($transactionId);
        
        $this->audit->logAction('transaction.begin', [
            'transaction_id' => $transactionId,
            'service' => $service,
            'method' => $method
        ]);
    }

    private function executeServiceMethod(string $service, string $method, array $params): mixed
    {
        $serviceInstance = $this->resolveService($service);
        
        if (!method_exists($serviceInstance, $method)) {
            throw new ServiceException("Method {$method} not found in service {$service}");
        }

        $result = $serviceInstance->$method(...$params);
        
        if ($this->shouldCache($service, $method)) {
            $this->cacheResult($service, $method, $params, $result);
        }

        return $result;
    }

    private function commitTransaction(string $transactionId): void
    {
        if (!isset($this->activeTransactions[$transactionId])) {
            throw new ServiceException("Transaction {$transactionId} not found");
        }

        $transaction = $this->activeTransactions[$transactionId];
        
        try {
            DB::commit();
            
            $this->audit->logAction('transaction.commit', [
                'transaction_id' => $transactionId,
                'duration' => microtime(true) - $transaction['start_time']
            ]);
            
        } finally {
            unset($this->activeTransactions[$transactionId]);
        }
    }

    private function rollbackTransaction(string $transactionId): void
    {
        if (isset($this->activeTransactions[$transactionId])) {
            DB::rollBack();
            
            $this->audit->logAction('transaction.rollback', [
                'transaction_id' => $transactionId,
                'duration' => microtime(true) - $this->activeTransactions[$transactionId]['start_time']
            ]);
            
            unset($this->activeTransactions[$transactionId]);
        }
    }

    private function resolveService(string $service): object
    {
        if (!isset($this->config['services'][$service])) {
            throw new ServiceException("Service {$service} not found");
        }

        return app($this->config['services'][$service]);
    }

    private function getIsolationLevel(string $service, string $method): string
    {
        return $this->config['isolation_levels'][$service][$method] 
            ?? $this->config['default_isolation_level'];
    }

    private function setTransactionIsolationLevel(string $transactionId): void
    {
        $level = $this->activeTransactions[$transactionId]['isolation_level'];
        
        DB::statement("SET TRANSACTION ISOLATION LEVEL {$level}");
    }

    private function shouldCache(string $service, string $method): bool
    {
        return in_array(
            "{$service}.{$method}",
            $this->config['cacheable_methods']
        );
    }

    private function cacheResult(string $service, string $method, array $params, mixed $result): void
    {
        $key = $this->generateCacheKey($service, $method, $params);
        $ttl = $this->config['cache_ttl'][$service][$method] ?? 3600;
        
        $this->cache->tags(["service.{$service}"])->put($key, $result, $ttl);
    }

    private function generateCacheKey(string $service, string $method, array $params): string
    {
        return sprintf(
            'service:%s:%s:%s',
            $service,
            $method,
            md5(serialize($params))
        );
    }

    private function checkRateLimit(string $service, string $method): void
    {
        $key = "ratelimit:service:{$service}:{$method}:" . request()->ip();
        
        $attempts = $this->cache->increment($key);
        if ($attempts === 1) {
            $this->cache->expire($key, 60);
        }
        
        $limit = $this->config['rate_limits'][$service][$method] 
            ?? $this->config['default_rate_limit'];
            
        if ($attempts > $limit) {
            throw new RateLimitException(
                "Rate limit exceeded for {$service}.{$method}"
            );
        }
    }

    private function generateTransactionId(): string
    {
        return uniqid('txn_', true);
    }

    private function handleServiceFailure(
        \Throwable $e,
        string $service,
        string $method,
        array $params
    ): void {
        $context = [
            'service' => $service,
            'method' => $method,
            'params' => $params,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ];

        $this->audit->logAction('service.failure', $context);
        
        if ($this->isCriticalService($service, $method)) {
            $this->security->triggerAlert('service_failure', $context);
        }
    }

    private function isCriticalService(string $service, string $method): bool
    {
        return in_array(
            "{$service}.{$method}",
            $this->config['critical_methods']
        );
    }
}
