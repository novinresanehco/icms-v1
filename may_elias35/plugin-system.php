<?php

namespace App\Core\Plugin;

use Illuminate\Support\Facades\{DB, Cache, Log};
use App\Core\Security\SecurityManager;
use App\Core\Exceptions\PluginException;

class PluginManager
{
    private SecurityManager $security;
    private PluginValidator $validator;
    private DependencyResolver $resolver;
    private PluginSandbox $sandbox;
    private AuditLogger $auditLogger;

    public function loadPlugin(string $pluginId, array $context): void
    {
        $this->security->executeCriticalOperation(
            fn() => $this->executePluginLoad($pluginId),
            $context
        );
    }

    private function executePluginLoad(string $pluginId): void
    {
        DB::beginTransaction();
        
        try {
            $plugin = $this->validateAndLoad($pluginId);
            $this->resolveDependencies($plugin);
            $this->initializePlugin($plugin);
            
            DB::commit();
            $this->auditLogger->logPluginLoad($plugin);
            
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->handlePluginFailure($e, $pluginId);
            throw $e;
        }
    }

    private function validateAndLoad(string $pluginId): Plugin
    {
        $plugin = $this->loadPluginData($pluginId);
        
        if (!$this->validator->validatePlugin($plugin)) {
            throw new PluginException('Plugin validation failed');
        }

        if (!$this->validator->validateSecurity($plugin)) {
            throw new PluginException('Plugin security check failed');
        }

        return $plugin;
    }

    private function resolveDependencies(Plugin $plugin): void
    {
        $dependencies = $this->resolver->resolveDependencies($plugin);
        
        foreach ($dependencies as $dependency) {
            if (!$this->loadDependency($dependency)) {
                throw new PluginException("Failed to load dependency: {$dependency->name}");
            }
        }
    }

    private function initializePlugin(Plugin $plugin): void
    {
        $sandboxConfig = $this->createSandboxConfig($plugin);
        
        $this->sandbox->execute(function() use ($plugin) {
            $instance = $this->instantiatePlugin($plugin);
            $this->registerHooks($instance);
            $this->initializeResources($instance);
        }, $sandboxConfig);
    }

    private function loadDependency(PluginDependency $dependency): bool
    {
        if ($this->isDependencyLoaded($dependency)) {
            return true;
        }

        return $this->loadPlugin($dependency->pluginId, [
            'context' => 'dependency_load',
            'parent_plugin' => $dependency->parent
        ]);
    }

    private function isDependencyLoaded(PluginDependency $dependency): bool
    {
        return Cache::tags(['plugins'])->has("plugin_loaded:{$dependency->pluginId}");
    }

    private function createSandboxConfig(Plugin $plugin): array
    {
        return [
            'memory_limit' => '128M',
            'time_limit' => 30,
            'allowed_classes' => $this->getAllowedClasses($plugin),
            'allowed_functions' => $this->getAllowedFunctions($plugin),
            'filesystem_access' => $this->getFilesystemAccess($plugin)
        ];
    }

    private function instantiatePlugin(Plugin $plugin): PluginInstance
    {
        $instance = new $plugin->class();
        
        if (!$instance instanceof PluginInterface) {
            throw new PluginException('Invalid plugin instance');
        }

        return $instance;
    }

    private function registerHooks(PluginInstance $instance): void
    {
        foreach ($instance->getHooks() as $hook) {
            if (!$this->validator->validateHook($hook)) {
                throw new PluginException("Invalid hook configuration: {$hook->name}");
            }

            $this->registerHook($instance, $hook);
        }
    }

    private function initializeResources(PluginInstance $instance): void
    {
        foreach ($instance->getResources() as $resource) {
            if (!$this->validator->validateResource($resource)) {
                throw new PluginException("Invalid resource: {$resource->name}");
            }

            $this->initializeResource($instance, $resource);
        }
    }

    private function registerHook(PluginInstance $instance, Hook $hook): void
    {
        $this->sandbox->execute(function() use ($instance, $hook) {
            $instance->registerHook($hook);
        }, [
            'allowed_calls' => $this->getAllowedHookCalls($hook),
            'timeout' => 5
        ]);
    }

    private function initializeResource(PluginInstance $instance, Resource $resource): void
    {
        $this->sandbox->execute(function() use ($instance, $resource) {
            $instance->initializeResource($resource);
        }, [
            'allowed_paths' => $this->getAllowedResourcePaths($resource),
            'timeout' => 10
        ]);
    }

    private function handlePluginFailure(\Throwable $e, string $pluginId): void
    {
        $this->auditLogger->logPluginFailure($pluginId, $e);
        $this->disablePlugin($pluginId);
        
        if ($this->isSystemCritical($e)) {
            throw new PluginException('Critical plugin failure', 0, $e);
        }
    }

    private function loadPluginData(string $pluginId): Plugin
    {
        return Cache::tags(['plugins'])->remember(
            "plugin:$pluginId",
            3600,
            fn() => $this->repository->findPlugin($pluginId)
        );
    }
}
