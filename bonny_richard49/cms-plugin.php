<?php

namespace App\Core\Plugin;

use Illuminate\Support\Facades\{DB, Cache, Event};
use App\Core\Security\SecurityContext;
use App\Core\Exceptions\PluginException;

class PluginManager
{
    private SecurityManager $security;
    private PluginRepository $repository;
    private PluginValidator $validator;
    private SandboxManager $sandbox;
    private DependencyResolver $resolver;
    private array $config;

    public function __construct(
        SecurityManager $security,
        PluginRepository $repository,
        PluginValidator $validator,
        SandboxManager $sandbox,
        DependencyResolver $resolver,
        array $config
    ) {
        $this->security = $security;
        $this->repository = $repository;
        $this->validator = $validator;
        $this->sandbox = $sandbox;
        $this->resolver = $resolver;
        $this->config = $config;
    }

    public function install(string $pluginPath, SecurityContext $context): Plugin
    {
        return $this->security->executeCriticalOperation(function() use ($pluginPath) {
            // Validate plugin package
            $manifest = $this->validator->validatePackage($pluginPath);
            
            // Check dependencies
            $this->resolver->checkDependencies($manifest->dependencies);
            
            // Verify security requirements
            $this->validator->validateSecurity($manifest);
            
            // Install plugin
            $plugin = $this->repository->install($manifest);
            
            // Initialize in sandbox
            $this->sandbox->initialize($plugin);
            
            Cache::tags(['plugins'])->flush();
            Event::dispatch('plugin.installed', $plugin);
            
            return $plugin;
        }, $context);
    }

    public function uninstall(string $pluginId, SecurityContext $context): bool
    {
        return $this->security->executeCriticalOperation(function() use ($pluginId) {
            $plugin = $this->repository->find($pluginId);
            if (!$plugin) {
                throw new PluginException("Plugin not found: {$pluginId}");
            }
            
            // Check for dependent plugins
            $dependents = $this->resolver->findDependents($plugin);
            if (count($dependents) > 0) {
                throw new PluginException('Plugin has active dependents');
            }
            
            // Cleanup sandbox
            $this->sandbox->cleanup($plugin);
            
            // Remove plugin
            $success = $this->repository->uninstall($plugin);
            
            if ($success) {
                Cache::tags(['plugins'])->flush();
                Event::dispatch('plugin.uninstalled', $plugin);
            }
            
            return $success;
        }, $context);
    }

    public function enable(string $pluginId, SecurityContext $context): bool
    {
        return $this->security->executeCriticalOperation(function() use ($pluginId) {
            $plugin = $this->repository->find($pluginId);
            if (!$plugin) {
                throw new PluginException("Plugin not found: {$pluginId}");
            }
            
            // Verify dependencies are enabled
            $this->resolver->verifyDependenciesEnabled($plugin);
            
            // Enable in sandbox
            $this->sandbox->enable($plugin);
            
            // Update status
            $success = $this->repository->updateStatus($plugin, 'enabled');
            
            if ($success) {
                Cache::tags(['plugins'])->flush();
                Event::dispatch('plugin.enabled', $plugin);
            }
            
            return $success;
        }, $context);
    }

    public function disable(string $pluginId, SecurityContext $context): bool
    {
        return $this->security->executeCriticalOperation(function() use ($pluginId) {
            $plugin = $this->repository->find($pluginId);
            if (!$plugin) {
                throw new PluginException("Plugin not found: {$pluginId}");
            }
            
            // Check for enabled dependents
            $dependents = $this->resolver->findEnabledDependents($plugin);
            if (count($dependents) > 0) {
                throw new PluginException('Plugin has enabled dependents');
            }
            
            // Disable in sandbox
            $this->sandbox->disable($plugin);
            
            // Update status
            $success = $this->repository->updateStatus($plugin, 'disabled');
            
            if ($success) {
                Cache::tags(['plugins'])->flush();
                Event::dispatch('plugin.disabled', $plugin);
            }
            
            return $success;
        }, $context);
    }
}

class SandboxManager
{
    private ResourceMonitor $monitor;
    private array $config;

    public function initialize(Plugin $plugin): void
    {
        $sandbox = $this->createSandbox($plugin);
        
        try {
            $this->loadPlugin($sandbox, $plugin);
            $this->verifyPlugin($sandbox, $plugin);
            $this->monitor->register($plugin->getId(), $this->config['resource_limits']);
        } catch (\Exception $e) {
            $this->cleanup($plugin);
            throw new PluginException("Plugin initialization failed: {$e->getMessage()}");
        }
    }

    public function enable(Plugin $plugin): void
    {
        $sandbox = $this->getSandbox($plugin);
        
        try {
            $sandbox->enable();
            $this->monitor->enable($plugin->getId());
        } catch (\Exception $e) {
            $this->disable($plugin);
            throw new PluginException("Plugin enable failed: {$e->getMessage()}");
        }
    }

    public function disable(Plugin $plugin): void
    {
        $sandbox = $this->getSandbox($plugin);
        $sandbox->disable();
        $this->monitor->disable($plugin->getId());
    }

    public function cleanup(Plugin $plugin): void
    {
        $sandbox = $this->getSandbox($plugin);
        $sandbox->cleanup();
        $this->monitor->unregister($plugin->getId());
    }

    private function createSandbox(Plugin $plugin): Sandbox
    {
        return new Sandbox([
            'id' => $plugin->getId(),
            'path' => $plugin->getPath(),
            'namespace' => $plugin->getNamespace(),
            'resources' => $this->config['resource_limits']
        ]);
    }

    private function loadPlugin(Sandbox $sandbox, Plugin $plugin): void
    {
        $sandbox->load($plugin->getBootstrapFile());
    }

    private function verifyPlugin(Sandbox $sandbox, Plugin $plugin): void
    {
        $sandbox->verify([
            'interfaces' => $this->config['required_interfaces'],
            'security' => $this->config['security_checks']
        ]);
    }

    private function getSandbox(Plugin $plugin): Sandbox
    {
        return new Sandbox(['id' => $plugin->getId()]);
    }
}

class DependencyResolver
{
    private PluginRepository $repository;

    public function checkDependencies(array $dependencies): void
    {
        foreach ($dependencies as $dependency => $version) {
            $plugin = $this->repository->findByName($dependency);
            
            if (!$plugin) {
                throw new PluginException("Required plugin not found: {$dependency}");
            }
            
            if (!$this->isVersionCompatible($plugin->getVersion(), $version)) {
                throw new PluginException(
                    "Plugin version incompatible: {$dependency} requires {$version}"
                );
            }
        }
    }

    public function findDependents(Plugin $plugin): array
    {
        return $this->repository->findDependents($plugin->getId());
    }

    public function findEnabledDependents(Plugin $plugin): array
    {
        return $this->repository->findEnabledDependents($plugin->getId());
    }

    public function verifyDependenciesEnabled(Plugin $plugin): void
    {
        foreach ($plugin->getDependencies() as $dependency => $version) {
            $dep = $this->repository->findByName($dependency);
            
            if (!$dep || !$dep->isEnabled()) {
                throw new PluginException(
                    "Required plugin not enabled: {$dependency}"
                );
            }
        }
    }

    private function isVersionCompatible(string $actual, string $required): bool
    {
        return version_compare($actual, $required, '>=');
    }
}

class PluginValidator
{
    private array $requiredFields = [
        'name',
        'version',
        'description',
        'author',
        'license'
    ];

    private array $securityChecks = [
        'file_access',
        'network_access',
        'database_access'
    ];

    public function validatePackage(string $path): object
    {
        if (!is_dir($path)) {
            throw new PluginException('Invalid plugin package path');
        }

        $manifest = $this->loadManifest($path);
        $this->validateManifest($manifest);
        
        return $manifest;
    }

    public function validateSecurity(object $manifest): void
    {
        foreach ($this->securityChecks as $check) {
            if (!$this->validateSecurityCheck($manifest, $check)) {
                throw new PluginException(
                    "Security check failed: {$check}"
                );
            }
        }
    }

    private function loadManifest(string $path): object
    {
        $manifestPath = $path . '/plugin.json';
        
        if (!file_exists($manifestPath)) {
            throw new PluginException('Plugin manifest not found');
        }
        
        $manifest = json_decode(file_get_contents($manifestPath));
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new PluginException('Invalid plugin manifest format');
        }
        
        return $manifest;
    }

    private function validateManifest(object $manifest): void
    {
        foreach ($this->requiredFields as $field) {
            if (!isset($manifest->$field)) {
                throw new PluginException(
                    "Required field missing in manifest: {$field}"
                );
            }
        }
    }

    private function validateSecurityCheck(object $manifest, string $check): bool
    {
        return isset($manifest->security->$check) && 
               $manifest->security->$check === true;
    }
}
