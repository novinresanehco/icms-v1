<?php
namespace App\Core;

class CoreBootstrap
{
    private Container $container;
    private array $criticalServices = [
        'security' => SecurityManager::class,
        'auth' => AuthenticationManager::class,
        'content' => ContentManager::class,
        'template' => TemplateManager::class,
        'monitor' => SystemMonitor::class,
        'recovery' => RecoveryManager::class
    ];

    public function boot(): void
    {
        DB::beginTransaction();
        try {
            $this->registerCriticalServices();
            $this->initializeSecurity();
            $this->initializeMonitoring();
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw new BootstrapException('Critical system boot failed', 0, $e);
        }
    }

    private function registerCriticalServices(): void
    {
        foreach ($this->criticalServices as $name => $class) {
            $this->container->singleton($name, $class);
        }
    }

    private function initializeSecurity(): void
    {
        $security = $this->container->make('security');
        $security->initializeProtection([
            'encryption' => true,
            'authentication' => true,
            'monitoring' => true
        ]);
    }
}

class ServiceContainer
{
    private array $bindings = [];
    private array $instances = [];
    private array $resolved = [];

    public function singleton(string $abstract, $concrete): void
    {
        $this->bindings[$abstract] = [
            'concrete' => $concrete,
            'shared' => true
        ];
    }

    public function make(string $abstract)
    {
        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        $concrete = $this->getConcrete($abstract);
        $object = $this->build($concrete);

        if ($this->isShared($abstract)) {
            $this->instances[$abstract] = $object;
        }

        return $object;
    }

    private function build($concrete)
    {
        try {
            $reflector = new \ReflectionClass($concrete);
            
            if (!$reflector->isInstantiable()) {
                throw new ContainerException("Class {$concrete} is not instantiable");
            }

            $constructor = $reflector->getConstructor();
            
            if (is_null($constructor)) {
                return new $concrete;
            }

            $dependencies = $this->resolveDependencies($constructor);
            return $reflector->newInstanceArgs($dependencies);
            
        } catch (\Exception $e) {
            throw new ContainerException("Unable to build {$concrete}", 0, $e);
        }
    }

    private function resolveDependencies(\ReflectionMethod $constructor): array
    {
        $dependencies = [];
        foreach ($constructor->getParameters() as $parameter) {
            $dependencies[] = $this->resolveDependency($parameter);
        }
        return $dependencies;
    }

    private function resolveDependency(\ReflectionParameter $parameter)
    {
        $type = $parameter->getType();
        
        if (!$type || $type->isBuiltin()) {
            if ($parameter->isDefaultValueAvailable()) {
                return $parameter->getDefaultValue();
            }
            throw new ContainerException("Unable to resolve dependency {$parameter->name}");
        }

        return $this->make($type->getName());
    }
}

class ConfigurationManager
{
    private array $config = [];
    private string $environment;

    public function load(string $environment): void
    {
        $this->environment = $environment;
        
        $this->loadCriticalConfigs([
            'app',
            'security',
            'database',
            'cache'
        ]);
    }

    private function loadCriticalConfigs(array $configs): void
    {
        foreach ($configs as $config) {
            $path = $this->getConfigPath($config);
            $this->config[$config] = require $path;
        }
    }

    private function getConfigPath(string $config): string
    {
        return config_path("{$config}.php");
    }

    public function get(string $key, $default = null)
    {
        return data_get($this->config, $key, $default);
    }
}