```php
namespace App\Core\Factory;

class ServiceFactory implements FactoryInterface
{
    private SecurityManager $security;
    private ValidationService $validator;
    private ContainerManager $container;

    public function create(string $service, array $parameters = []): object
    {
        return $this->security->executeProtected(function() use ($service, $parameters) {
            // Validate service creation
            $this->validator->validateServiceCreation($service, $parameters);
            
            // Create service
            $instance = $this->createService($service, $parameters);
            
            // Validate instance
            if (!$this->validator->validateInstance($instance)) {
                throw new InvalidServiceException();
            }

            return $instance;
        });
    }

    private function createService(string $service, array $parameters): object
    {
        $constructor = $this->getConstructor($service);
        $dependencies = $this->resolveDependencies($constructor, $parameters);
        
        return new $service(...$dependencies);
    }

    private function resolveDependencies(ReflectionMethod $constructor, array $parameters): array
    {
        $dependencies = [];

        foreach ($constructor->getParameters() as $parameter) {
            $dependencies[] = $this->resolveDependency($parameter, $parameters);
        }

        return $dependencies;
    }

    private function resolveDependency(ReflectionParameter $parameter, array $parameters)
    {
        if (array_key_exists($parameter->getName(), $parameters)) {
            return $parameters[$parameter->getName()];
        }

        if ($parameter->hasType() && !$parameter->getType()->isBuiltin()) {
            return $this->container->resolve($parameter->getType()->getName());
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        throw new DependencyResolutionException();
    }
}
```
