<?php

namespace App\Core\Bootstrap;

use Illuminate\Support\Facades\{DB, Cache, Log};
use App\Core\Security\SecurityManager;
use App\Core\Exceptions\BootstrapException;

class SystemBootstrap
{
    private SecurityManager $security;
    private EnvironmentValidator $envValidator;
    private SystemHealthCheck $healthCheck;
    private AuditLogger $auditLogger;

    public function initialize(array $config): void
    {
        try {
            DB::beginTransaction();
            
            $this->validateEnvironment();
            $this->initializeCore();
            $this->runSecurityChecks();
            $this->initializeSubsystems();
            
            DB::commit();
            
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->handleBootstrapFailure($e);
            throw new BootstrapException('System initialization failed', 0, $e);
        }
    }

    private function validateEnvironment(): void
    {
        if (!$this->envValidator->checkRequirements()) {
            throw new BootstrapException('Environment requirements not met');
        }

        if (!$this->envValidator->checkPermissions()) {
            throw new BootstrapException('Invalid system permissions');
        }

        $this->envValidator->enforceSecuritySettings();
    }

    private function initializeCore(): void
    {
        $this->security->initializeSecurity();
        $this->initializeCache();
        $this->initializeStorage();
        $this->initializeSessionHandler();
    }

    private function runSecurityChecks(): void
    {
        if (!$this->healthCheck->verifySystemIntegrity()) {
            throw new BootstrapException('System integrity check failed');
        }

        if (!$this->healthCheck->verifySecurityConfig()) {
            throw new BootstrapException('Security configuration invalid');
        }

        $this->healthCheck->enforceSecurityPolicies();
    }

    private function initializeSubsystems(): void
    {
        $initOrder = [
            'database',
            'cache',
            'security',
            'content',
            'media',
            'template',
            'api'
        ];

        foreach ($initOrder as $system) {
            $this->initializeSubsystem($system);
        }
    }

    private function initializeSubsystem(string $system): void
    {
        $initializer = $this->getInitializer($system);
        
        if (!$initializer->checkPrerequisites()) {
            throw new BootstrapException("$system prerequisites not met");
        }

        if (!$initializer->initialize()) {
            throw new BootstrapException("$system initialization failed");
        }

        $this->auditLogger->logSubsystemInit($system);
    }

    private function initializeCache(): void
    {
        Cache::flush();
        
        $drivers = config('cache.drivers');
        foreach ($drivers as $driver => $config) {
            $this->validateCacheDriver($driver, $config);
        }

        Cache::tags(['system'])->put('boot_time', now(), 3600);
    }

    private function initializeStorage(): void
    {
        $paths = config('storage.required_paths');
        foreach ($paths as $path) {
            if (!$this->ensureStoragePath($path)) {
                throw new BootstrapException("Failed to initialize storage: $path");
            }
        }
    }

    private function initializeSessionHandler(): void
    {
        $handler = config('session.handler');
        $config = config('session.config');
        
        if (!$this->validateSessionHandler($handler, $config)) {
            throw new BootstrapException('Invalid session configuration');
        }

        $this->initializeSessionEncryption();
    }

    private function handleBootstrapFailure(\Throwable $e): void
    {
        $this->auditLogger->logCriticalFailure('bootstrap', $e);
        
        try {
            $this->executeEmergencyShutdown();
        } catch (\Throwable $shutdownError) {
            Log::emergency('Emergency shutdown failed', [
                'error' => $shutdownError->getMessage(),
                'trace' => $shutdownError->getTraceAsString()
            ]);
        }
    }

    private function executeEmergencyShutdown(): void
    {
        Cache::tags(['system'])->flush();
        DB::disconnect();
        
        $this->auditLogger->logEmergencyShutdown();
    }

    private function validateSessionHandler(string $handler, array $config): bool
    {
        return $this->security->validateSessionConfig($handler, $config) &&
               $this->healthCheck->verifySessionSecurity($handler);
    }

    private function initializeSessionEncryption(): void
    {
        if (!$key = $this->security->generateSessionKey()) {
            throw new BootstrapException('Failed to initialize session encryption');
        }

        Cache::tags(['system', 'security'])->put('session_key', $key, 3600);
    }

    private function ensureStoragePath(string $path): bool
    {
        if (!file_exists($path) && !mkdir($path, 0750, true)) {
            return false;
        }

        return chmod($path, 0750);
    }

    private function validateCacheDriver(string $driver, array $config): void
    {
        if (!$this->healthCheck->verifyCacheDriver($driver, $config)) {
            throw new BootstrapException("Invalid cache configuration: $driver");
        }
    }
}
