<?php

namespace App\Core\System;

use App\Core\Security\SecurityManager;
use App\Core\Config\ConfigurationManager;
use App\Core\Monitoring\MonitoringService;
use App\Exceptions\InitializationException;

class SystemInitializer
{
    private SecurityManager $security;
    private ConfigurationManager $config;
    private MonitoringService $monitor;
    private array $components = [];
    private bool $initialized = false;

    public function __construct(
        SecurityManager $security,
        ConfigurationManager $config,
        MonitoringService $monitor
    ) {
        $this->security = $security;
        $this->config = $config;
        $this->monitor = $monitor;
    }

    public function initialize(): void
    {
        if ($this->initialized) {
            throw new InitializationException('System already initialized');
        }

        $operationId = $this->monitor->startOperation('system.initialize');

        try {
            // Load critical configurations
            $this->loadCriticalConfigs();
            
            // Initialize security layer
            $this->initializeSecurity();
            
            // Initialize core components
            $this->initializeCoreComponents();
            
            // Verify system state
            $this->verifySystemState();
            
            $this->initialized = true;
            
        } catch (\Throwable $e) {
            $this->handleInitializationFailure($e, $operationId);
            throw $e;
            
        } finally {
            $this->monitor->stopOperation($operationId);
        }
    }

    private function loadCriticalConfigs(): void
    {
        $configs = [
            'security' => $this->config->loadConfig('security'),
            'database' => $this->config->loadConfig('database'),
            'cache' => $this->config->loadConfig('cache'),
            'monitoring' => $this->config->loadConfig('monitoring')
        ];

        foreach ($configs as $name => $config) {
            if (!$this->validateConfig($name, $config)) {
                throw new InitializationException("Invalid $name configuration");
            }
        }

        $this->components['configs'] = $configs;
    }

    private function initializeSecurity(): void
    {
        $this->security->initialize($this->components['configs']['security']);
        
        if (!$this->security->verifyInitialization()) {
            throw new InitializationException('Security initialization failed');
        }

        $this->monitor->recordMetric('security.initialized', 1);
    }

    private function initializeCoreComponents(): void
    {
        $coreComponents = [
            'database' => fn() => $this->initializeDatabase(),
            'cache' => fn() => $this->initializeCache(),
            'session' => fn() => $this->initializeSession(),
            'filesystem' => fn() => $this->initializeFilesystem()
        ];

        foreach ($coreComponents as $name => $initializer) {
            try {
                $this->components[$name] = $initializer();
                $this->monitor->recordMetric("component.$name.initialized", 1);
            } catch (\Throwable $e) {
                throw new InitializationException(
                    "Failed to initialize $name component: " . $e->getMessage(),
                    0,
                    $e
                );
            }
        }
    }

    private function verifySystemState(): void
    {
        $checks = [
            'configs_loaded' => !empty($this->components['configs']),
            'security_active' => $this->security->isActive(),
            'components_ready' => $this->verifyComponents(),
            'system_healthy' => $this->checkSystemHealth()
        ];

        foreach ($checks as $check => $result) {
            if (!$result) {
                throw new InitializationException("System state verification failed: $check");
            }
        }
    }

    private function validateConfig(string $name, array $config): bool
    {
        return $this->config->validateConfig(
            $config,
            $this->config->loadSchema($name)
        );
    }

    private function initializeDatabase(): object
    {
        $config = $this->components['configs']['database'];
        
        // Verify database connection
        $connection = DB::connection();
        $connection->getPdo();
        
        // Set critical database settings
        $connection->statement('SET SESSION TRANSACTION ISOLATION LEVEL SERIALIZABLE');
        
        return $connection;
    }

    private function initializeCache(): object
    {
        $config = $this->components['configs']['cache'];
        
        $cache = Cache::driver($config['driver']);
        
        // Verify cache connection
        if (!$cache->set('test', true)) {
            throw new InitializationException('Cache verification failed');
        }
        
        return $cache;
    }

    private function initializeSession(): void
    {
        // Configure secure session settings
        ini_set('session.cookie_secure', 1);
        ini_set('session.cookie_httponly', 1);
        ini_set('session.use_strict_mode', 1);
        
        // Start session with custom handler
        $handler = new SecureSessionHandler($this->security);
        session_set_save_handler($handler, true);
        session_start();
    }

    private function initializeFilesystem(): object
    {
        $filesystem = Storage::disk('local');
        
        // Verify filesystem access
        if (!$filesystem->put('test', 'test')) {
            throw new InitializationException('Filesystem verification failed');
        }
        
        return $filesystem;
    }

    private function verifyComponents(): bool
    {
        foreach ($this->components as $name => $component) {
            if (!$component || ($component instanceof Verifiable && !$component->verify())) {
                return false;
            }
        }
        return true;
    }

    private function checkSystemHealth(): bool
    {
        return $this->monitor->checkSystemHealth([
            'memory_usage' => memory_get_usage(true),
            'cpu_usage' => sys_getloadavg()[0],
            'disk_space' => disk_free_space('/')
        ]);
    }

    private function handleInitializationFailure(\Throwable $e, string $operationId): void
    {
        $this->monitor->logEvent('initialization_failed', [
            'operation_id' => $operationId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        $this->monitor->triggerAlert('initialization_failed', [
            'error' => $e->getMessage(),
            'severity' => 'critical'
        ]);

        // Attempt cleanup
        $this->cleanup();
    }

    private function cleanup(): void
    {
        foreach ($this->components as $name => $component) {
            if ($component instanceof Cleanable) {
                try {
                    $component->cleanup();
                } catch (\Throwable $e) {
                    $this->monitor->logEvent('cleanup_failed', [
                        'component' => $name,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }
    }
}
