<?php

namespace App\Core\Container;

class CriticalContainer 
{
    private array $bindings = [];
    private array $instances = [];
    private Monitor $monitor;
    
    public function bind(string $abstract, $concrete): void 
    {
        try {
            $this->validate($abstract, $concrete);
            $this->bindings[$abstract] = $concrete;
        } catch (\Exception $e) {
            $this->monitor->logBindingFailure($abstract, $e);
            throw $e;
        }
    }
    
    public function resolve(string $abstract)
    {
        try {
            // Check singleton instance
            if (isset($this->instances[$abstract])) {
                return $this->instances[$abstract];
            }

            $concrete = $this->bindings[$abstract] ?? $abstract;
            
            // Create instance
            $instance = $this->build($concrete);
            
            // Cache if singleton
            if ($this->isSingleton($abstract)) {
                $this->instances[$abstract] = $instance;
            }
            
            return $instance;
            
        } catch (\Exception $e) {
            $this->monitor->logResolutionFailure($abstract, $e);
            throw new ContainerException("Failed to resolve: $abstract", 0, $e);
        }
    }

    private function validate(string $abstract, $concrete): void
    {
        if (!$this->isValidBinding($concrete)) {
            throw new ContainerException("Invalid binding for: $abstract");
        }
    }

    private function build($concrete)
    {
        // For closures, execute
        if ($concrete instanceof \Closure) {
            return $concrete($this);
        }

        // Resolve constructor dependencies
        $reflector = new \ReflectionClass($concrete);
        $constructor = $reflector->getConstructor();
        
        if (!$constructor) {
            return $reflector->newInstance();
        }

        $dependencies = array_map(
            fn($param) => $this->resolve($param->getType()->getName()),
            $constructor->getParameters()
        );

        return $reflector->newInstanceArgs($dependencies);
    }
}
