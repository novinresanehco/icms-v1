<?php

namespace App\Core\DependencyInjection;

class Container
{
    private array $bindings = [];
    private array $instances = [];
    private array $aliases = [];
    private array $contextual = [];

    public function bind(string $abstract, $concrete = null, bool $shared = false): void
    {
        if (is_null($concrete)) {
            $concrete = $abstract;
        }

        $this->bindings[$abstract] = [
            'concrete' => $concrete,
            'shared' => $shared
        ];
    }

    public function singleton(string $abstract, $concrete = null): void
    {
        $this->bind($abstract, $concrete, true);
    }

    public function alias(string $abstract, string $alias): void
    {
        $this->aliases[$alias] = $abstract;
    }

    public function instance(string $abstract, $instance): void
    {
        $this->instances[$abstract] = $instance;
    }

    public function when(string $concrete): ContextualBindingBuilder
    {
        return new ContextualBindingBuilder($this, $concrete);
    }

    public function make(string $abstract, array $parameters = [])
    {
        $abstract = $this->getAlias($abstract);

        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        $concrete = $this->getConcrete($abstract);

        if ($this->isBuildable($concrete, $abstract)) {
            $object = $this->build($concrete, $parameters);
        } else {
            $object = $this->make($concrete, $parameters);
        }

        if ($this->isShared($abstract)) {
            $this->instances[$abstract] = $object;
        }

        return $object;
    }

    private function build($concrete, array $parameters = [])
    {
        if ($concrete instanceof \Closure) {
            return $concrete($this, $parameters);
        }

        $reflector = new \ReflectionClass($concrete);
        if (!$reflector->isInstantiable()) {
            throw new BindingResolutionException("Target [$concrete] is not instantiable.");
        }

        $constructor = $reflector->getConstructor();
        if (is_null($constructor)) {
            return new $concrete;
        }

        $dependencies = $this->resolveDependencies($constructor->getParameters(), $parameters);
        return $reflector->newInstanceArgs($dependencies);
    }

    private function resolveDependencies(array $dependencies, array $parameters): array
    {
        $results = [];

        foreach ($dependencies as $dependency) {
            if (array_key_exists($dependency->name, $parameters)) {
                $results[] = $parameters[$dependency->name];
                continue;
            }

            if (is_null($dependency->getType()) || $dependency->getType()->isBuiltin()) {
                if ($dependency->isDefaultValueAvailable()) {
                    $results[] = $dependency->getDefaultValue();
                } else {
                    throw new BindingResolutionException("Unresolvable dependency [{$dependency->name}]");
                }
            } else {
                $results[] = $this->make($dependency->getType()->getName());
            }
        }

        return $results;
    }

    private function getConcrete(string $abstract)
    {
        if (isset($this->bindings[$abstract])) {
            return $this->bindings[$abstract]['concrete'];
        }

        return $abstract;
    }

    private function getAlias(string $abstract): string
    {
        return isset($this->aliases[$abstract]) 
            ? $this->getAlias($this->aliases[$abstract])
            : $abstract;
    }

    private function isBuildable($concrete, string $abstract): bool
    {
        return $concrete === $abstract || $concrete instanceof \Closure;
    }

    private function isShared(string $abstract): bool
    {
        return isset($this->bindings[$abstract]['shared']) &&
               $this->bindings[$abstract]['shared'] === true;
    }
}

class ContextualBindingBuilder
{
    private Container $container;
    private string $concrete;
    private array $needs = [];

    public function __construct(Container $container, string $concrete)
    {
        $this->container = $container;
        $this->concrete = $concrete;
    }

    public function needs(string $abstract): self
    {
        $this->needs[] = $abstract;
        return $this;
    }

    public function give($implementation): void
    {
        foreach ($this->needs as $need) {
            $this->container->contextual[$this->concrete][$need] = $implementation;
        }
    }
}

class ServiceProvider
{
    protected Container $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    public function register(): void
    {
        // Register bindings
    }

    public function provides(): array
    {
        return [];
    }

    public function when(string $concrete): ContextualBindingBuilder
    {
        return $this->container->when($concrete);
    }
}

class BindingResolutionException extends \Exception {}
