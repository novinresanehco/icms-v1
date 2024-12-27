$compiler->compile($parsed);
    }

    protected function tokenize(string $template): array
    {
        $tokens = [];
        $current = '';
        $inTag = false;
        
        for ($i = 0; $i < strlen($template); $i++) {
            $char = $template[$i];
            
            if ($char === '{' && $template[$i + 1] === '{') {
                if ($current !== '') {
                    $tokens[] = ['type' => 'text', 'content' => $current];
                }
                $current = '';
                $inTag = true;
                $i++;
                continue;
            }
            
            if ($char === '}' && $template[$i + 1] === '}' && $inTag) {
                $tokens[] = ['type' => 'variable', 'content' => trim($current)];
                $current = '';
                $inTag = false;
                $i++;
                continue;
            }
            
            $current .= $char;
        }
        
        if ($current !== '') {
            $tokens[] = ['type' => 'text', 'content' => $current];
        }
        
        return $tokens;
    }

    protected function parse(array $tokens): array
    {
        $parsed = [];
        
        foreach ($tokens as $token) {
            if ($token['type'] === 'variable') {
                $parsed[] = [
                    'type' => 'php',
                    'content' => "<?php echo e(\${$token['content']}); ?>"
                ];
            } else {
                $parsed[] = $token;
            }
        }
        
        return $parsed;
    }

    protected function validateCompiled(string $php): void
    {
        if (preg_match('/\b(eval|exec|system|passthru)\b/', $php)) {
            throw new SecurityException('Unsafe PHP code detected');
        }
    }
}

class ServiceContainer implements ContainerInterface 
{
    private array $bindings = [];
    private array $instances = [];
    private array $aliases = [];
    
    public function bind(string $abstract, $concrete = null, bool $shared = false): void
    {
        if (is_null($concrete)) {
            $concrete = $abstract;
        }
        
        if (!$concrete instanceof Closure) {
            $concrete = $this->getClosure($abstract, $concrete);
        }
        
        $this->bindings[$abstract] = compact('concrete', 'shared');
    }
    
    public function singleton(string $abstract, $concrete = null): void
    {
        $this->bind($abstract, $concrete, true);
    }

    public function instance(string $abstract, $instance): void
    {
        $this->instances[$abstract] = $instance;
        
        if (isset($this->aliases[$abstract])) {
            foreach ($this->aliases[$abstract] as $alias) {
                $this->instances[$alias] = $instance;
            }
        }
    }
    
    public function alias(string $abstract, string $alias): void
    {
        if (!isset($this->aliases[$abstract])) {
            $this->aliases[$abstract] = [];
        }
        
        $this->aliases[$abstract][] = $alias;
        
        if (isset($this->instances[$abstract])) {
            $this->instances[$alias] = $this->instances[$abstract];
        }
    }

    public function make(string $abstract)
    {
        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }
        
        $concrete = $this->getConcrete($abstract);
        
        if ($this->isBuildable($concrete, $abstract)) {
            $object = $this->build($concrete);
        } else {
            $object = $this->make($concrete);
        }
        
        if (isset($this->bindings[$abstract]['shared']) && 
            $this->bindings[$abstract]['shared']) {
            $this->instances[$abstract] = $object;
        }
        
        return $object;
    }

    protected function getConcrete(string $abstract)
    {
        if (!isset($this->bindings[$abstract])) {
            return $abstract;
        }

        return $this->bindings[$abstract]['concrete'];
    }
    
    protected function isBuildable($concrete, string $abstract): bool
    {
        return $concrete === $abstract || $concrete instanceof Closure;
    }

    protected function build($concrete)
    {
        if ($concrete instanceof Closure) {
            return $concrete($this);
        }
        
        $reflector = new ReflectionClass($concrete);
        
        if (!$reflector->isInstantiable()) {
            throw new BindingResolutionException("Target [$concrete] is not instantiable");
        }
        
        $constructor = $reflector->getConstructor();
        
        if (is_null($constructor)) {
            return new $concrete;
        }
        
        $dependencies = $constructor->getParameters();
        $instances = $this->resolveDependencies($dependencies);
        
        return $reflector->newInstanceArgs($instances);
    }
    
    protected function resolveDependencies(array $dependencies): array
    {
        $results = [];
        
        foreach ($dependencies as $dependency) {
            if ($type = $dependency->getType()) {
                $results[] = $this->make($type->getName());
            } elseif ($dependency->isDefaultValueAvailable()) {
                $results[] = $dependency->getDefaultValue();
            } else {
                throw new BindingResolutionException(
                    "Unresolvable dependency resolving [{$dependency->name}]"
                );
            }
        }
        
        return $results;
    }
    
    protected function getClosure(string $abstract, $concrete): Closure
    {
        return function ($container) use ($abstract, $concrete) {
            if ($abstract === $concrete) {
                return $container->build($concrete);
            }
            
            return $container->make($concrete);
        };
    }
}