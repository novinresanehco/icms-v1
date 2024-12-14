<?php

namespace App\Core\Plugin;

class PluginManager implements PluginManagerInterface 
{
    private SecurityManager $security;
    private Repository $repository;
    private ValidationService $validator;
    private EventDispatcher $events;
    private DependencyResolver $resolver;
    private array $loadedPlugins = [];

    public function register(string $name, array $config): PluginResult 
    {
        DB::beginTransaction();
        
        try {
            $this->security->validateCriticalOperation([
                'action' => 'plugin.register',
                'plugin' => $name,
                'config' => $config
            ]);

            $validated = $this->validator->validate($config, [
                'name' => 'required|string',
                'version' => 'required|string',
                'description' => 'string',
                'provider' => 'required|string',
                'dependencies' => 'array',
                'permissions' => 'array',
                'settings' => 'array'
            ]);

            // Verify plugin provider class
            $this->validatePluginProvider($validated['provider']);

            // Check dependencies
            $this->resolver->validateDependencies($validated['dependencies'] ?? []);

            $plugin = $this->repository->create([
                'name' => $validated['name'],
                'version' => $validated['version'],
                'description' => $validated['description'] ?? '',
                'provider' => $validated['provider'],
                'dependencies' => $validated['dependencies'] ?? [],
                'permissions' => $validated['permissions'] ?? [],
                'settings' => $validated['settings'] ?? [],
                'status' => 'registered',
                'registered_at' => now()
            ]);

            $this->events->dispatch(new PluginRegistered($plugin));
            
            DB::commit();
            return new PluginResult($plugin);

        } catch (\Exception $e) {
            DB::rollBack();
            throw new PluginException('Plugin registration failed', 0, $e);
        }
    }

    public function load(string $name): bool 
    {
        try {
            $plugin = $this->repository->findByName($name);
            
            if (!$plugin) {
                throw new PluginNotFoundException("Plugin not found: {$name}");
            }

            if (isset($this->loadedPlugins[$name])) {
                return true;
            }

            $this->security->validateCriticalOperation([
                'action' => 'plugin.load',
                'plugin' => $name
            ]);

            // Load dependencies first
            foreach ($plugin->dependencies as $dependency) {
                $this->load($dependency);
            }

            // Initialize plugin provider
            $provider = $this->initializeProvider($plugin->provider);
            
            // Boot plugin
            $provider->boot();
            
            $this->loadedPlugins[$name] = $provider;
            
            $this->events->dispatch(new PluginLoaded($plugin));
            
            return true;

        } catch (\Exception $e) {
            throw new PluginException("Failed to load plugin: {$name}", 0, $e);
        }
    }

    public function enable(string $name): bool 
    {
        DB::beginTransaction();
        
        try {
            $this->security->validateCriticalOperation([
                'action' => 'plugin.enable',
                'plugin' => $name
            ]);

            $plugin = $this->repository->findByName($name);
            
            if (!$plugin) {
                throw new PluginNotFoundException("Plugin not found: {$name}");
            }

            // Verify dependencies are enabled
            foreach ($plugin->dependencies as $dependency) {
                if (!$this->isEnabled($dependency)) {
                    throw new PluginDependencyException(
                        "Required dependency not enabled: {$dependency}"
                    );
                }
            }

            // Load and initialize plugin
            $this->load($name);
            
            // Update status
            $this->repository->update($plugin->id, [
                'status' => 'enabled',
                'enabled_at' => now()
            ]);

            $this->events->dispatch(new PluginEnabled($plugin));
            
            DB::commit();
            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new PluginException("Failed to enable plugin: {$name}", 0, $e);
        }
    }

    public function disable(string $name): bool 
    {
        DB::beginTransaction();
        
        try {
            $this->security->validateCriticalOperation([
                'action' => 'plugin.disable',
                'plugin' => $name
            ]);

            $plugin = $this->repository->findByName($name);
            
            if (!$plugin) {
                throw new PluginNotFoundException("Plugin not found: {$name}");
            }

            // Check if other enabled plugins depend on this
            $dependents = $this->resolver->findDependents($name);
            if (!empty($dependents)) {
                throw new PluginDependencyException(
                    "Plugin cannot be disabled due to dependencies: " . 
                    implode(', ', $dependents)
                );
            }

            // Update status
            $this->repository->update($plugin->id, [
                'status' => 'disabled',
                'disabled_at' => now()
            ]);

            // Remove from loaded plugins
            unset($this->loadedPlugins[$name]);

            $this->events->dispatch(new PluginDisabled($plugin));
            
            DB::commit();
            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new PluginException("Failed to disable plugin: {$name}", 0, $e);
        }
    }

    private function validatePluginProvider(string $provider): void 
    {
        if (!class_exists($provider)) {
            throw new PluginException("Plugin provider class not found: {$provider}");
        }

        if (!is_subclass_of($provider, PluginProvider::class)) {
            throw new PluginException(
                "Plugin provider must extend " . PluginProvider::class
            );
        }
    }

    private function initializeProvider(string $provider): PluginProvider 
    {
        return new $provider($this->security, $this->events);
    }

    private function isEnabled(string $name): bool 
    {
        $plugin = $this->repository->findByName($name);
        return $plugin && $plugin->status === 'enabled';
    }
}
