<?php

namespace App\Core\Security;

use App\Core\Cache\CacheManager;
use App\Core\Monitoring\MonitoringService;
use App\Exceptions\SecurityException;

class SecurityCore implements SecurityInterface
{
    private CacheManager $cache;
    private MonitoringService $monitor;
    private array $config;
    
    public function __construct(
        CacheManager $cache,
        MonitoringService $monitor,
        array $config
    ) {
        $this->cache = $cache;
        $this->monitor = $monitor;
        $this->config = $config;
    }

    public function validateSecureOperation(callable $operation, SecurityContext $context): mixed
    {
        $operationId = $this->monitor->startOperation('security.validate');

        try {
            // Pre-operation security validation 
            $this->validateContext($context);
            
            // Execute with monitoring
            $result = DB::transaction(function() use ($operation, $context) {
                $this->prepareSecureEnvironment();
                $output = $operation();
                $this->validateOutput($output);
                return $output;
            });

            // Post-operation validation
            $this->verifyOperationIntegrity($result);
            
            return $result;

        } catch (\Throwable $e) {
            $this->handleSecurityFailure($e, $operationId);
            throw new SecurityException('Operation failed security validation', 0, $e);
        } finally {
            $this->monitor->stopOperation($operationId);
        }
    }

    public function verifyAccess(string $resource, string $action, SecurityContext $context): bool
    {
        try {
            // Check cached result
            $cacheKey = "access:{$context->userId}:{$resource}:{$action}";
            if ($cached = $this->cache->get($cacheKey)) {
                return $cached === 'granted';
            }

            // Perform complete access check
            $hasAccess = $this->performAccessCheck($resource, $action, $context);
            
            // Cache result
            $this->cache->set($cacheKey, $hasAccess ? 'granted' : 'denied', 3600);
            
            return $hasAccess;

        } catch (\Throwable $e) {
            $this->monitor->logSecurityEvent('access_check_failed', [
                'resource' => $resource,
                'action' => $action,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    private function validateContext(SecurityContext $context): void
    {
        if (!$context->isValid()) {
            throw new SecurityException('Invalid security context');
        }

        if (!$this->verifyToken($context->token)) {
            throw new SecurityException('Invalid security token');
        }
    }

    private function prepareSecureEnvironment(): void
    {
        ini_set('display_errors', 0);
        ini_set('log_errors', 1);
        
        // Set secure execution flags
        if ($this->config['strict_mode']) {
            error_reporting(E_ALL | E_STRICT);
            set_error_handler([$this, 'handleError']);
        }
    }

    private function validateOutput($output): void
    {
        if ($output instanceof SecurityAwareData) {
            if (!$output->verifyIntegrity()) {
                throw new SecurityException('Output integrity validation failed');
            }
        }
    }

    private function verifyOperationIntegrity($result): void 
    {
        if (!$this->verifyDataIntegrity($result)) {
            throw new SecurityException('Operation integrity check failed');
        }
    }

    private function handleSecurityFailure(\Throwable $e, string $operationId): void
    {
        $this->monitor->logSecurityEvent('security_failure', [
            'operation_id' => $operationId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    private function handleError($severity, $message, $file, $line): void
    {
        throw new ErrorException($message, 0, $severity, $file, $line);
    }
}
