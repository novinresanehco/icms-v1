<?php

namespace App\Core\Plugin;

use Illuminate\Support\Facades\{Cache, Event};
use App\Core\Security\SecurityManager;
use App\Core\Performance\PerformanceManager;
use App\Core\Plugin\Events\PluginEvent;
use App\Core\Plugin\DTOs\{PluginData, PluginConfig};
use App\Core\Exceptions\{PluginException, ValidationException};

class PluginManager implements PluginInterface 
{
    private SecurityManager $security;
    private PerformanceManager $performance;
    private PluginRepository $repository;
    private ValidationService $validator;
    private DependencyResolver $resolver;
    private AuditLogger $auditLogger;

    private array $loadedPlugins = [];

    public function register(string $name, array $config): PluginData
    {
        return $this->security->executeCriticalOperation(
            new PluginOperation('register', $name),
            new SecurityContext(['type' => 'plugin_registration']),
            function() use ($name, $config) {
                try {
                    $validated = $this->validator->validatePluginConfig($config);
                    
                    if ($this->repository->exists($name)) {
                        throw new PluginException('Plugin already registered');
                    }

                    $this->validateDependencies($validated['dependencies'] ?? []);
                    
                    $plugin = $this->repository->create([
                        'name' => $name,
                        'config' => $validated,
                        'status' => 'registered',
                        'version' => $validated['version']
                    ]);

                    $this->auditLogger->logPluginRegistration($plugin);
                    event(new PluginEvent(PluginEvent::REGISTERED, $plugin));
                    
                    return new PluginData($plugin);
                    
                } catch (\Exception $e) {
                    $this->auditLogger->logRegistrationFailure($name, $e);
                    throw new PluginException('Plugin registration failed: ' . $e->getMessage(), 0, $e);
                }
            }
        );
    }

    public function load(string $name): bool
    {
        return $this->security->executeCriticalOperation(
            new PluginOperation('load', $name),
            new SecurityContext(['type' => 'plugin_loading']),
            function() use ($name) {
                try {
                    if (isset($this->loadedPlugins[$name])) {
                        return true;
                    }

                    $plugin = $this->repository->findByName($name);
                    if (!$plugin) {
                        throw new PluginException('Plugin not found');
                    }

                    $this->loadDependencies($plugin->config['dependencies'] ?? []);
                    
                    $instance = $this->createPluginInstance($plugin);
                    $this->validatePluginInstance($instance);
                    
                    if (method_exists($instance, 'boot')) {
                        $instance->boot();
                    }

                    $this->loadedPlugins[$name] = $instance;
                    
                    $this->auditLogger->logPluginLoad($plugin);
                    event(new PluginEvent(PluginEvent::LOADED, $plugin));
                    
                    return true;
                    
                } catch (\Exception $e) {
                    $this->auditLogger->logLoadFailure($name, $e);
                    throw new PluginException('Plugin load failed: ' . $e->getMessage(), 0, $e);
                }
            }
        );
    }

    public function unload(string $name): bool
    {
        return $this->security->executeCriticalOperation(
            new PluginOperation('unload', $name),
            new SecurityContext(['type' => 'plugin_unloading']),
            function() use ($name) {
                try {
                    if (!isset($this->loadedPlugins[$name])) {
                        return true;
                    }

                    $instance = $this->loadedPlugins[$name];
                    if (method_exists($instance, 'shutdown')) {
                        $instance->shutdown();
                    }

                    unset($this->loadedPlugins[$name]);
                    
                    $plugin = $this->repository->findByName($name);
                    $this->auditLogger->logPluginUnload($plugin);
                    event(new PluginEvent(PluginEvent::UNLOADED, $plugin));
                    
                    return true;
                    
                } catch (\Exception $e) {
                    $this->auditLogger->logUnloadFailure($name, $e);
                    throw new PluginException('Plugin unload failed: ' . $e->getMessage(), 0, $e);
                }
            }
        );
    }

    protected function loadDependencies(array $dependencies): void
    {
        foreach ($dependencies as $dependency) {
            if (!isset($this->loadedPlugins[$dependency])) {
                $this->load($dependency);
            }
        }
    }

    protected function validateDependencies(array $dependencies): void
    {
        foreach ($dependencies as $dependency) {
            $plugin = $this->repository->findByName($dependency);
            if (!$plugin) {
                throw new PluginException("Dependency not found: {$dependency}");
            }
            
            if ($plugin->status !== 'active') {
                throw new PluginException("Dependency not active: {$dependency}");
            }
        }
    }

    protected function createPluginInstance(Plugin $plugin): object
    {
        $class = $plugin->config['class'];
        if (!class_exists($class)) {
            throw new PluginException("Plugin class not found: {$class}");
        }

        return new $class($this->createPluginContainer($plugin));
    }

    protected function validatePluginInstance(object $instance): void
    {
        if (!$instance instanceof PluginInterface) {
            throw new PluginException('Invalid plugin instance');
        }
    }

    protected function createPluginContainer(Plugin $plugin): PluginContainer
    {
        return new PluginContainer($plugin, $this->security, $this->performance);
    }

    public function getRegisteredPlugins(): array
    {
        return $this->performance->withCaching(
            'registered_plugins',
            fn() => $this->repository->getAllActive(),
            ['plugins'],
            3600
        );
    }

    public function getLoadedPlugins(): array
    {
        return array_keys($this->loadedPlugins);
    }
}
