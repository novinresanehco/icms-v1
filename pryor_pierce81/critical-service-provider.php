<?php

namespace App\Core\Providers;

class CriticalServiceProvider
{
    private Container $container;
    private Monitor $monitor;

    public function register(): void
    {
        try {
            // Register security services
            $this->registerSecurity();
            
            // Register core services
            $this->registerCore();
            
            // Register infrastructure services
            $this->registerInfrastructure();
            
            // Verify registrations
            $this->verifyServices();
            
        } catch (\Exception $e) {
            $this->handleRegistrationFailure($e);
        }
    }

    private function registerSecurity(): void
    {
        $this->container->singleton(SecurityManager::class);
        $this->container->singleton(AuthManager::class);
        $this->container->singleton(ValidationService::class);
    }

    private function registerCore(): void
    {
        $this->container->singleton(ContentManager::class);
        $this->container->singleton(CacheManager::class);
        $this->container->singleton(EventDispatcher::class);
    }

    private function verifyServices(): void
    {
        foreach ($this->getRequiredServices() as $service) {
            if (!$this->container->has($service)) {
                throw new ServiceRegistrationException("Missing required service: $service");
            }
        }
    }
}
