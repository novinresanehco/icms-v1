<?php

namespace App\Core\Protection;

use App\Core\Monitoring\MonitoringService;
use App\Core\Cache\CacheManager;
use App\Exceptions\ProtectionException;

class ProtectionManager implements ProtectionInterface
{
    private MonitoringService $monitor;
    private CacheManager $cache;
    private array $config;
    private array $guards = [];

    public function __construct(
        MonitoringService $monitor,
        CacheManager $cache,
        array $config
    ) {
        $this->monitor = $monitor;
        $this->cache = $cache;
        $this->config = $config;
    }

    public function executeProtectedOperation(callable $operation, array $context): mixed
    {
        $operationId = $this->monitor->startOperation('protection.execute');
        
        try {
            // Initialize protection
            $this->initializeProtection($context);
            
            // Create backup
            $backupId = $this->createBackup();
            
            // Execute with guards
            $result = $this->executeWithGuards($operation, $context);
            
            // Verify result
            $this->verifyProtectedResult($result);
            
            return $result;
            
        } catch (\Throwable $e) {
            $this->handleProtectionFailure($e, $operationId, $backupId);
            throw $e;
            
        } finally {
            $this->monitor->stopOperation($operationId);
            $this->cleanupProtection();
        }
    }

    public function enforceProtection(string $resource): void
    {
        // Access control
        $this->enforceAccessControl($resource);
        
        // Data protection
        $this->enforceDataProtection($resource);
        
        // Resource protection
        $this->enforceResourceProtection($resource);
        
        // Integrity protection
        $this->enforceIntegrityProtection($resource);
    }

    public function verifyProtection(string $resource): array
    {
        return [
            'access' => $this->verifyAccessProtection($resource),
            'data' => $this->verifyDataProtection($resource),
            'resources' => $this->verifyResourceProtection($resource),
            'integrity' => $this->verifyIntegrityProtection($resource)
        ];
    }

    private function initializeProtection(array $context): void
    {
        // Initialize guards
        foreach ($this->config['guards'] as $guard => $config) {
            $this->initializeGuard($guard, $config, $context);
        }
        
        // Set protection flags
        $this->setProtectionFlags();
        
        // Configure error handling
        $this->configureErrorHandling();
    }

    private function createBackup(): string
    {
        $backupId = uniqid('backup_', true);
        
        try {
            // Backup critical data
            $data = [
                'database' => $this->backupDatabase(),
                'files' => $this->backupFiles(),
                'state' => $this->backupState()
            ];
            
            // Store backup
            $this->cache->set("backup:$backupId", $data, $this->config['backup_ttl']);
            
            return $backupId;
            
        } catch (\Throwable $e) {
            $this->monitor->triggerAlert('backup_failed', [
                'error' => $e->getMessage()
            ], 'critical');
            throw new ProtectionException('Failed to create backup', 0, $e);
        }
    }

    private function executeWithGuards(callable $operation, array $context): mixed
    {
        foreach ($this->guards as $guard) {
            $guard->beforeExecution($context);
        }
        
        try {
            $result = $operation();
            
            foreach ($this->guards as $guard) {
                $guard->afterExecution($result, $context);
            }
            
            return $result;
            
        } catch (\Throwable $e) {
            foreach ($this->guards as $guard) {
                $guard->onFailure($e, $context);
            }
            throw $e;
        }
    }

    private function verifyProtectedResult($result): void
    {
        if ($result instanceof ProtectedData) {
            if (!$this->verifyDataIntegrity($result)) {
                throw new ProtectionException('Protected result integrity check failed');
            }
        }
    }

    private function initializeGuard(string $guard, array $config, array $context): void
    {
        $guardClass = $this->config['guard_classes'][$guard];
        $this->guards[$guard] = new $guardClass($config, $context);
    }

    private function setProtectionFlags(): void
    {
        foreach ($this->config['protection_flags'] as $flag => $value) {
            ini_set($flag, $value);
        }
    }

    private function configureErrorHandling(): void
    {
        set_error_handler([$this, 'handleError']);
        register_shutdown_function([$this, 'handleShutdown']);
    }

    private function handleError($severity, $message, $file, $line): void
    {
        throw new ErrorException($message, 0, $severity, $file, $line);
    }

    private function handleShutdown(): void
    {
        $error = error_get_last();
        if ($error !== null) {
            $this->monitor->triggerAlert('fatal_error', $error, 'critical');
        }
    }

    private function backupDatabase(): array
    {
        return DB::transaction(function() {
            $tables = $this->getProtectedTables();
            $backup = [];
            
            foreach ($tables as $table) {
                $backup[$table] = DB::table($table)->get();
            }
            
            return $backup;
        });
    }

    private function backupFiles(): array
    {
        $paths = $this->config['protected_paths'];
        $backup = [];
        
        foreach ($paths as $path) {
            $backup[$path] = $this->createFileBackup($path);
        }
        
        return $backup;
    }

    private function backupState(): array
    {
        return [
            'cache' => $this->cache->export(),
            'session' => $_SESSION ?? [],
            'environment' => $_ENV
        ];
    }
}
