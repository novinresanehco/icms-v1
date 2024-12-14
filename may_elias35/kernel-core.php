<?php

namespace App\Core\Kernel;

class SystemKernel implements KernelInterface
{
    private ConfigurationManager $config;
    private SecurityManager $security;
    private ServiceRegistry $services;
    private LogManager $logger;
    private ErrorHandler $errors;
    private StateManager $state;

    public function bootstrap(): void
    {
        try {
            $this->initializeKernel();
            $this->validateEnvironment();
            $this->loadCriticalServices();
            $this->initializeSecurity();
            $this->startMonitoring();
            
        } catch (\Exception $e) {
            $this->handleBootstrapFailure($e);
        }
    }

    private function initializeKernel(): void
    {
        $this->state->setStage('kernel_init');
        $this->config->load('critical');
        
        if (!$this->validateCriticalConfig()) {
            throw new KernelException('Invalid critical configuration');
        }
        
        $this->logger->initializeSystem([
            'environment' => $this->config->get('app.env'),
            'stage' => 'kernel_init',
            'timestamp' => now()
        ]);
    }

    private function validateEnvironment(): void
    {
        $requirements = [
            'php' => '8.1.0',
            'memory_limit' => '256M',
            'max_execution_time' => 30,
            'extensions' => ['openssl', 'pdo', 'mbstring', 'tokenizer']
        ];

        foreach ($requirements as $key => $value) {
            if (!$this->checkRequirement($key, $value)) {
                throw new EnvironmentException("Environment requirement not met: {$key}");
            }
        }
    }

    private function loadCriticalServices(): void
    {
        $this->state->setStage('service_load');
        
        $criticalServices = [
            'security' => SecurityManager::class,
            'database' => DatabaseManager::class,
            'cache' => CacheManager::class,
            'session' => SessionManager::class,
            'auth' => AuthenticationManager::class
        ];

        foreach ($criticalServices as $name => $class) {
            try {
                $service = $this->services->make($class);
                $this->validateService($service);
                $this->services->register($name, $service);
                
            } catch (\Exception $e) {
                throw new ServiceException("Failed to load critical service: {$name}", 0, $e);
            }
        }
    }

    private function initializeSecurity(): void
    {
        $this->state->setStage('security_init');
        
        try {
            $this->security->initialize([
                'encryption_key' => $this->config->get('security.key'),
                'cipher' => $this->config->get('security.cipher'),
                'providers' => $this->config->get('security.providers')
            ]);

            if (!$this->security->validateState()) {
                throw new SecurityException('Security system validation failed');
            }
            
        } catch (\Exception $e) {
            throw new SecurityException('Security initialization failed', 0, $e);
        }
    }

    private function startMonitoring(): void
    {
        $this->state->setStage('monitoring_init');
        
        $monitors = [
            new PerformanceMonitor($this->config->get('monitoring.performance')),
            new SecurityMonitor($this->config->get('monitoring.security')),
            new ResourceMonitor($this->config->get('monitoring.resources')),
            new ErrorMonitor($this->config->get('monitoring.errors'))
        ];

        foreach ($monitors as $monitor) {
            $monitor->initialize();
            $monitor->startMonitoring();
        }
    }

    private function validateCriticalConfig(): bool
    {
        $requiredKeys = [
            'app.key',
            'security.key',
            'security.cipher',
            'database.default',
            'cache.default',
            'session.driver'
        ];

        foreach ($requiredKeys as $key) {
            if (!$this->config->has($key)) {
                return false;
            }
        }

        return true;
    }

    private function checkRequirement(string $key, mixed $value): bool
    {
        switch ($key) {
            case 'php':
                return version_compare(PHP_VERSION, $value, '>=');
                
            case 'memory_limit':
                return $this->parseBytes(ini_get('memory_limit')) >= 
                       $this->parseBytes($value);
                
            case 'max_execution_time':
                return ini_get('max_execution_time') >= $value;
                
            case 'extensions':
                return count(array_diff($value, get_loaded_extensions())) === 0;
                
            default:
                return false;
        }
    }

    private function validateService(object $service): void
    {
        if (!$service instanceof ServiceInterface) {
            throw new ServiceException('Invalid service implementation');
        }

        if (method_exists($service, 'validate') && !$service->validate()) {
            throw new ServiceException('Service validation failed');
        }
    }

    private function handleBootstrapFailure(\Exception $e): void
    {
        $this->errors->handleCriticalError($e);
        
        $this->logger->logCriticalError([
            'stage' => $this->state->getCurrentStage(),
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        if ($e instanceof SecurityException) {
            $this->security->handleSecurityFailure($e);
        }

        throw new KernelException('System bootstrap failed', 0, $e);
    }

    private function parseBytes(string $size): int
    {
        $unit = strtolower(substr($size, -1));
        $value = (int) $size;
        
        switch ($unit) {
            case 'g':
                $value *= 1024;
            case 'm':
                $value *= 1024;
            case 'k':
                $value *= 1024;
        }
        
        return $value;
    }
}
