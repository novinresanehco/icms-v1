<?php

namespace App\Core\Container;

class ServiceContainer implements ContainerInterface
{
    private SecurityManager $security;
    private array $bindings = [];
    private array $instances = [];
    private array $aliases = [];
    private DependencyResolver $resolver;

    public function bind(string $abstract, $concrete, bool $shared = false): void
    {
        $this->bindings[$abstract] = [
            'concrete' => $concrete,
            'shared' => $shared,
            'secure' => true
        ];
    }

    public function singleton(string $abstract, $concrete): void
    {
        $this->bind($abstract, $concrete, true);
    }

    public function get(string $abstract)
    {
        return $this->security->executeCriticalOperation(
            new ResolveServiceOperation(
                $abstract,
                $this->resolver,
                $this->instances,
                $this->bindings
            )
        );
    }

    public function has(string $abstract): bool
    {
        return isset($this->bindings[$abstract]) || 
               isset($this->instances[$abstract]) ||
               isset($this->aliases[$abstract]);
    }
}

class DependencyResolver
{
    private SecurityManager $security;
    private TypeValidator $validator;
    private array $resolving = [];

    public function resolve(string $abstract, array $bindings): object
    {
        if (isset($this->resolving[$abstract])) {
            throw new CircularDependencyException($abstract);
        }

        $this->resolving[$abstract] = true;

        try {
            $concrete = $this->getConcrete($abstract, $bindings);
            $instance = $this->build($concrete);
            
            unset($this->resolving[$abstract]);
            return $instance;
        } catch (\Exception $e) {
            unset($this->resolving[$abstract]);
            throw $e;
        }
    }

    private function build($concrete): object
    {
        if ($concrete instanceof \Closure) {
            return $this->security->executeCriticalOperation(
                new BuildClosureOperation($concrete)
            );
        }

        $reflection = new \ReflectionClass($concrete);
        
        if (!$reflection->isInstantiable()) {
            throw new ContainerException("Class $concrete is not instantiable");
        }

        $constructor = $reflection->getConstructor();
        if (!$constructor) {
            return $reflection->newInstance();
        }

        $dependencies = $this->resolveDependencies(
            $constructor->getParameters()
        );

        return $reflection->newInstanceArgs($dependencies);
    }

    private function resolveDependencies(array $dependencies): array
    {
        return array_map(function(\ReflectionParameter $dependency) {
            return $this->resolveDependency($dependency);
        }, $dependencies);
    }

    private function resolveDependency(\ReflectionParameter $dependency)
    {
        $type = $dependency->getType();
        
        if (!$type || $type->isBuiltin()) {
            if ($dependency->isDefaultValueAvailable()) {
                return $dependency->getDefaultValue();
            }
            
            throw new ContainerException(
                "Unresolvable dependency: {$dependency->getName()}"
            );
        }

        return $this->resolve($type->getName(), []);
    }
}

class ResolveServiceOperation implements CriticalOperation
{
    private string $abstract;
    private DependencyResolver $resolver;
    private array $instances;
    private array $bindings;

    public function execute(): object
    {
        if (isset($this->instances[$this->abstract])) {
            return $this->instances[$this->abstract];
        }

        if (!isset($this->bindings[$this->abstract])) {
            throw new ContainerException("No binding for $this->abstract");
        }

        $binding = $this->bindings[$this->abstract];
        $instance = $this->resolver->resolve(
            $this->abstract,
            $this->bindings
        );

        if ($binding['shared']) {
            $this->instances[$this->abstract] = $instance;
        }

        return $instance;
    }

    public function getRequiredPermissions(): array
    {
        return ['container.resolve'];
    }
}

class BuildClosureOperation implements CriticalOperation
{
    private \Closure $closure;

    public function execute(): object
    {
        return $this->closure->__invoke();
    }

    public function getRequiredPermissions(): array
    {
        return ['container.build'];
    }
}

class TypeValidator
{
    private SecurityManager $security;
    private array $allowedTypes;

    public function validateType(string $type): bool
    {
        if (!in_array($type, $this->allowedTypes)) {
            throw new SecurityException("Type $type is not allowed");
        }

        return true;
    }

    public function validateInstance(object $instance): bool
    {
        return $this->validateType(get_class($instance));
    }
}
