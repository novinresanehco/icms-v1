<?php

namespace App\Core\Plugin;

use App\Core\Security\SecurityManager;
use App\Core\Protection\CoreProtectionSystem;
use App\Core\Exceptions\{PluginException, SecurityException};

class PluginManager implements PluginManagerInterface
{
    private SecurityManager $security;
    private CoreProtectionSystem $protection;
    private ValidationService $validator;
    private MetricsCollector $metrics;
    private ContainerInterface $container;

    public function loadPlugin(string $identifier, SecurityContext $context): PluginInstance
    {
        return $this->protection->executeProtectedOperation(
            function() use ($identifier, $context) {
                $plugin = $this->validatePlugin($identifier);
                $this->verifyDependencies($plugin);
                $this->validateSecurity($plugin);
                
                $instance = $this->createPluginInstance($plugin);
                $this->registerServices($instance);
                
                return $instance;
            },
            $context
        );
    }

    public function registerExtension(string $point, callable $extension, SecurityContext $context): void
    {
        $this->protection->executeProtectedOperation(
            function() use ($point, $extension, $context) {
                $validatedPoint = $this->validateExtensionPoint($point);
                $this->validateExtensionCallback($extension);
                
                $this->registerExtensionPoint($validatedPoint, $extension);
                $this->notifyExtensionRegistered($validatedPoint);
            },
            $context
        );
    }

    public function executeHook(string $hook, array $params, SecurityContext $context): mixed
    {
        return $this->protection->executeProtectedOperation(
            function() use ($hook, $params, $context) {
                $validatedHook = $this->validateHook($hook);
                $validatedParams = $this->validateHookParams($params);
                
                return $this->executeHookchain($validatedHook, $validatedParams);
            },
            $context
        );
    }

    private function validatePlugin(string $identifier): Plugin
    {
        $plugin = $this->loadPluginMetadata($identifier);
        
        if (!$this->validator->validatePlugin($plugin)) {
            throw new PluginException('Invalid plugin metadata');
        }

        if (!$this->security->validatePluginSignature($plugin)) {
            throw new SecurityException('Invalid plugin signature');
        }

        return $plugin;
    }

    private function verifyDependencies(Plugin $plugin): void
    {
        foreach ($plugin->getDependencies() as $dependency) {
            if (!$this->isDependencyAvailable($dependency)) {
                throw new PluginException("Missing dependency: {$dependency->getName()}");
            }

            if (!$this->isDependencyCompatible($dependency)) {
                throw new PluginException("Incompatible dependency version: {$dependency->getName()}");
            }
        }
    }

    private function validateSecurity(Plugin $plugin): void
    {
        $securityScan = $this->security->scanPlugin($plugin);
        
        if ($securityScan->hasVulnerabilities()) {
            throw new SecurityException('Plugin security scan failed');
        }

        if ($securityScan->hasUnsafePermissions()) {
            throw new SecurityException('Plugin requires unsafe permissions');
        }
    }

    private function createPluginInstance(Plugin $plugin): PluginInstance
    {
        $instance = new PluginInstance($plugin);
        
        $instance->setContainer(
            $this->createSecureContainer($plugin)
        );

        $instance->setPermissions(
            $this->calculatePluginPermissions($plugin)
        );

        return $instance;
    }

    private function registerServices(PluginInstance $instance): void
    {
        foreach ($instance->getServices() as $service) {
            $this->validateService($service);
            $this->container->register($service);
        }
    }

    private function validateExtensionPoint(string $point): string
    {
        if (!$this->isValidExtensionPoint($point)) {
            throw new PluginException('Invalid extension point');
        }

        return $point;
    }

    private function validateExtensionCallback(callable $extension): void
    {
        if (!$this->security->isSecureCallback($extension)) {
            throw new SecurityException('Unsafe extension callback');
        }
    }

    private function executeHookchain(string $hook, array $params): mixed
    {
        $chain = $this->buildHookChain($hook);
        return $chain->execute($params);
    }

    private function createSecureContainer(Plugin $plugin): ContainerInterface
    {
        return new SecureContainer(
            $plugin->getNamespace(),
            $this->calculateContainerPermissions($plugin),
            $this->security
        );
    }

    private function calculatePluginPermissions(Plugin $plugin): array
    {
        $basePermissions = $plugin->getRequestedPermissions();
        $securityPolicy = config('plugins.security_policy');
        
        return array_filter($basePermissions, function($permission) use ($securityPolicy) {
            return $securityPolicy->isPermissionAllowed($permission);
        });
    }

    private function validateService(ServiceDefinition $service): void
    {
        if (!$this->validator->validateService($service)) {
            throw new PluginException('Invalid service definition');
        }

        if (!$this->security->validateServiceSecurity($service)) {
            throw new SecurityException('Service failed security validation');
        }
    }

    private function buildHookChain(string $hook): HookChain
    {
        $handlers = $this->getHookHandlers($hook);
        
        return new HookChain(
            $handlers,
            $this->security,
            $this->metrics
        );
    }
}
