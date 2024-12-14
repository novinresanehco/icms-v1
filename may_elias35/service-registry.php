<?php

namespace App\Core\Services;

class ServiceRegistry implements RegistryInterface
{
    private SecurityManager $security;
    private ValidationService $validator;
    private ContainerManager $container;
    private DependencyResolver $resolver;
    private AuditLogger $logger;
    private MetricsCollector $metrics;

    private array $services = [];
    private array $instances = [];
    private array $bindings = [];

    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        ContainerManager $container,
        DependencyResolver $resolver,
        AuditLogger $logger,
        MetricsCollector $metrics
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->container = $container;
        $this->resolver = $resolver;
        $this->logger = $logger;
        $this->metrics = $metrics;
    }

    public function register(string $id, ServiceDefinition $definition): void
    {
        $registrationId = uniqid('reg_', true);
        
        try {
            $this->validateServiceDefinition($id, $definition);
            $this->security->validateServiceRegistration($definition);

            $this->validateDependencies($definition);
            $this->registerService($registrationId, $id, $definition);
            
            $this->logger->logServiceRegistration($registrationId, $id);

        } catch (\Exception $e) {
            $this->handleRegistrationFailure($registrationId, $id, $e);
            throw new RegistrationException('Service registration failed', 0, $e);
        }
    }

    public function resolve(string $id): object
    {
        $resolutionId = uniqid('res_', true);
        
        try {
            if (isset($this->instances[$id])) {
                return $this->instances[$id];
            }

            $this->security->validateServiceResolution($id);
            $definition = $this->getServiceDefinition($id);
            
            $instance = $this->createServiceInstance($resolutionId, $definition);
            $this->instances[$id] = $instance;
            
            $this->logger->logServiceResolution($resolutionId, $id);
            return $instance;

        } catch (\Exception $e) {
            $this->handleResolutionFailure($resolutionId, $id, $e);
            throw new ResolutionException('Service resolution failed', 0, $e);
        }
    }

    private function validateServiceDefinition(string $id, ServiceDefinition $definition): void
    {
        if (!$this->validator->validateServiceId($id)) {
            throw new ValidationException('Invalid service identifier');
        }

        if (!$this->validator->validateServiceClass($definition->getClass())) {
            throw new ValidationException('Invalid service class');
        }

        if ($definition->isSingleton() && !$definition->isShareable()) {
            throw new ValidationException('Invalid singleton configuration');
        }
    }

    private function validateDependencies(ServiceDefinition $definition): void
    {
        $dependencies = $this->resolver->analyzeDependencies($definition);
        
        foreach ($dependencies as $dependency) {
            if (!$this->services[$dependency] ?? false) {
                throw new DependencyException("Missing dependency: {$dependency}");
            }

            if (!$this->security->validateDependency($definition, $dependency)) {
                throw new SecurityException("Unauthorized dependency: {$dependency}");
            }
        }
    }

    private function registerService(string $registrationId, string $id, ServiceDefinition $definition): void
    {
        $this->services[$id] = $definition;

        if ($definition->hasFactory()) {
            $this->bindings[$id] = $definition->getFactory();
        }

        $this->metrics->recordServiceRegistration($id, [
            'type' => $definition->getType(),
            'singleton' => $definition->isSingleton(),
            'dependencies' => count($this->resolver->analyzeDependencies($definition))
        ]);
    }

    private function getServiceDefinition(string $id): ServiceDefinition
    {
        if (!isset($this->services[$id])) {
            throw new ServiceNotFoundException("Service not found: {$id}");
        }

        return $this->services[$id];
    }

    private function createServiceInstance(string $resolutionId, ServiceDefinition $definition): object
    {
        $startTime = microtime(true);

        try {
            if ($definition->hasFactory()) {
                $instance = $this->createFromFactory($definition);
            } else {
                $instance = $this->createFromClass($definition);
            }

            $this->validateInstance($instance, $definition);
            $this->initializeInstance($instance, $definition);

            $this->metrics->recordServiceCreation(
                $definition->getClass(),
                microtime(true) - $startTime
            );

            return $instance;

        } catch (\Exception $e) {
            $this->handleCreationFailure($resolutionId, $definition, $e);
            throw $e;
        }
    }

    private function createFromFactory(ServiceDefinition $definition): object
    {
        $factory = $this->bindings[$definition->getId()];
        return $factory($this->container);
    }

    private function createFromClass(ServiceDefinition $definition): object
    {
        $dependencies = $this->resolveDependencies($definition);
        return new ($definition->getClass())(...$dependencies);
    }

    private function resolveDependencies(ServiceDefinition $definition): array
    {
        $dependencies = [];
        
        foreach ($definition->getDependencies() as $dependency) {
            $dependencies[] = $this->resolve($dependency);
        }

        return $dependencies;
    }

    private function validateInstance(object $instance, ServiceDefinition $definition): void
    {
        if (!$instance instanceof $definition->getClass()) {
            throw new InstanceException('Invalid service instance type');
        }

        if (!$this->validator->validateServiceInstance($instance)) {
            throw new ValidationException('Invalid service instance');
        }
    }

    private function initializeInstance(object $instance, ServiceDefinition $definition): void
    {
        if (method_exists($instance, 'initialize')) {
            $instance->initialize();
        }

        if ($definition->hasInitializer()) {
            $initializer = $definition->getInitializer();
            $initializer($instance);
        }
    }

    private function handleRegistrationFailure(string $registrationId, string $id, \Exception $e): void
    {
        $this->logger->logRegistrationFailure($registrationId, $id, [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        if ($e instanceof SecurityException) {
            $this->security->handleSecurityIncident($registrationId, $e);
        }
    }

    private function handleResolutionFailure(string $resolutionId, string $id, \Exception $e): void
    {
        $this->logger->logResolutionFailure($resolutionId, $id, [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        $this->metrics->recordResolutionFailure($id);
    }

    private function handleCreationFailure(string $resolutionId, ServiceDefinition $definition, \Exception $e): void
    {
        $this->logger->logCreationFailure($resolutionId, $definition->getClass(), [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        $this->metrics->recordCreationFailure($definition->getClass());
    }
}
