<?php

namespace App\Core\Container;

use Illuminate\Support\Facades\{Log, Cache};
use App\Core\Security\SecurityManager;
use App\Core\Interfaces\{ContainerInterface, LifecycleInterface};
use App\Core\Exceptions\{ContainerException, SecurityException};

class ServiceContainer implements ContainerInterface
{
    private SecurityManager $security;
    private ValidationService $validator;
    private array $bindings = [];
    private array $instances = [];
    private array $contextual = [];
    private array $config;

    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        array $config
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->config = $config;
    }

    public function bind(string $abstract, $concrete = null, bool $shared = false): void
    {
        $this->security->executeCriticalOperation(
            fn() => $this->executeBind($abstract, $concrete, $shared),
            ['action' => 'bind_service', 'abstract' => $abstract]
        );
    }

    public function singleton(string $abstract, $concrete = null): void
    {
        $this->bind($abstract, $concrete, true);
    }

    public function make(string $abstract, array $parameters = []): object
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->resolveDependency($abstract, $parameters),
            ['action' => 'resolve_service', 'abstract' => $abstract]
        );
    }

    protected function executeBind(string $abstract, $concrete = null, bool $shared = false): void
    {
        $this->validateBinding($abstract, $concrete);

        $concrete = $concrete ?? $abstract;
        $concrete = !is_string($concrete) ? $concrete : fn($container) => $container->build($concrete);

        $this->bindings[$abstract] = compact('concrete', 'shared');
    }

    protected function resolveDependency(string $abstract, array $parameters = []): object
    {
        $this->validateDependency($abstract);

        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        $concrete = $this->getConcrete($abstract);
        $object = $this->build($concrete, $parameters);

        if ($this->isShared($abstract)) {
            $this->instances[$abstract] = $object;
        }

        $this->initializeInstance($object);
        return $object;
    }

    protected function build($concrete, array $parameters = []): object
    {
        if ($concrete instanceof \Closure) {
            return $concrete($this, $parameters);
        }

        try {
            $reflector = new \ReflectionClass($concrete);
            
            if (!$reflector->isInstantiable()) {
                throw new ContainerException("Class {$concrete} is not instantiable");
            }

            $constructor = $reflector->getConstructor();
            
            if (is_null($constructor)) {
                return $reflector->newInstance();
            }

            $dependencies = $this->resolveDependencies(
                $constructor->getParameters(),
                $parameters
            );

            $instance = $reflector->newInstanceArgs($dependencies);
            return $this->configureInstance($instance, $concrete);

        } catch (\ReflectionException $e) {
            throw new ContainerException("Error resolving class {$concrete}", 0, $e);
        }
    }

    protected function resolveDependencies(array $parameters, array $primitives = []): array
    {
        $dependencies = [];

        foreach ($parameters as $parameter) {
            if (array_key_exists($parameter->name, $primitives)) {
                $dependencies[] = $primitives[$parameter->name];
                continue;
            }

            $dependency = $parameter->getType() && !$parameter->getType()->isBuiltin()
                ? $this->resolveClass($parameter)
                : $this->resolveDefault($parameter);

            if ($dependency === null && !$parameter->isOptional()) {
                throw new ContainerException("Unresolvable dependency: {$parameter->name}");
            }

            $dependencies[] = $dependency;
        }

        return $dependencies;
    }

    protected function resolveClass(\ReflectionParameter $parameter)
    {
        try {
            return $this->make($parameter->getType()->getName());
        } catch (\Exception $e) {
            if ($parameter->isOptional()) {
                return $parameter->getDefaultValue();
            }
            throw $e;
        }
    }

    protected function resolveDefault(\ReflectionParameter $parameter)
    {
        return $parameter->isDefaultValueAvailable()
            ? $parameter->getDefaultValue()
            : null;
    }

    protected function configureInstance(object $instance, string $concrete): object
    {
        if ($this->hasConfiguration($concrete)) {
            $this->applyConfiguration($instance, $this->getConfiguration($concrete));
        }

        if ($instance instanceof LifecycleInterface) {
            $instance->initialize();
        }

        return $instance;
    }

    protected function validateBinding(string $abstract, $concrete): void
    {
        if (!$this->validator->validateServiceBinding($abstract, $concrete)) {
            throw new ContainerException('Invalid service binding');
        }

        if ($this->isRestrictedService($abstract)) {
            throw new SecurityException('Restricted service binding attempted');
        }
    }

    protected function validateDependency(string $abstract): void
    {
        if (!$this->validator->validateDependency($abstract)) {
            throw new ContainerException('Invalid dependency');
        }

        if (!isset($this->bindings[$abstract]) && !class_exists($abstract)) {
            throw new ContainerException("Target [{$abstract}] is not instantiable");
        }
    }

    protected function getConcrete(string $abstract)
    {
        if (isset($this->bindings[$abstract])) {
            return $this->bindings[$abstract]['concrete'];
        }

        return $abstract;
    }

    protected function isShared(string $abstract): bool
    {
        return isset($this->bindings[$abstract]['shared']) &&
               $this->bindings[$abstract]['shared'];
    }

    protected function isRestrictedService(string $abstract): bool
    {
        return in_array($abstract, $this->config['restricted_services'] ?? []);
    }

    protected function hasConfiguration(string $concrete): bool
    {
        return isset($this->config['service_configuration'][$concrete]);
    }

    protected function getConfiguration(string $concrete): array
    {
        return $this->config['service_configuration'][$concrete];
    }

    protected function applyConfiguration(object $instance, array $config): void
    {
        foreach ($config as $property => $value) {
            if (property_exists($instance, $property)) {
                $instance->{$property} = $value;
            }
        }
    }

    protected function initializeInstance(object $instance): void
    {
        try {
            if ($instance instanceof LifecycleInterface) {
                $instance->initialize();
            }

            if (method_exists($instance, 'boot')) {
                $instance->boot();
            }

        } catch (\Exception $e) {
            $this->handleInitializationFailure($instance, $e);
            throw new ContainerException('Service initialization failed', 0, $e);
        }
    }

    protected function handleInitializationFailure(object $instance, \Exception $e): void
    {
        Log::error('Service initialization failed', [
            'service' => get_class($instance),
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        if ($this->isSystemCritical($instance)) {
            throw $e;
        }
    }

    protected function isSystemCritical(object $instance): bool
    {
        return in_array(
            get_class($instance),
            $this->config['critical_services'] ?? []
        );
    }
}
