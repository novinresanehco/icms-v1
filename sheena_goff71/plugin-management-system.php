<?php

namespace App\Core\Plugin;

use Illuminate\Support\Facades\{Cache, Event, Log};
use App\Core\Security\SecurityManager;
use App\Core\Interfaces\{PluginManagerInterface, ContainerInterface};
use App\Core\Exceptions\{PluginException, SecurityException};

class PluginManager implements PluginManagerInterface
{
    private SecurityManager $security;
    private ContainerInterface $container;
    private DependencyResolver $resolver;
    private ValidationService $validator;
    private array $config;
    private array $loadedPlugins = [];

    public function __construct(
        SecurityManager $security,
        ContainerInterface $container,
        DependencyResolver $resolver,
        ValidationService $validator,
        array $config
    ) {
        $this->security = $security;
        $this->container = $container;
        $this->resolver = $resolver;
        $this->validator = $validator;
        $this->config = $config;
    }

    public function loadPlugin(string $identifier): bool
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executePluginLoad($identifier),
            ['action' => 'load_plugin', 'plugin' => $identifier]
        );
    }

    public function executePlugin(string $identifier, string $method, array $params = []): mixed
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executePluginMethod($identifier, $method, $params),
            ['action' => 'execute_plugin', 'plugin' => $identifier, 'method' => $method]
        );
    }

    protected function executePluginLoad(string $identifier): bool
    {
        try {
            $this->validatePluginIdentifier($identifier);
            
            if (isset($this->loadedPlugins[$identifier])) {
                return true;
            }

            $manifest = $this->loadPluginManifest($identifier);
            $this->validatePluginManifest($manifest);
            
            $dependencies = $this->resolveDependencies($manifest['dependencies'] ?? []);
            $plugin = $this->instantiatePlugin($manifest, $identifier);
            
            $this->initializePlugin($plugin, $manifest);
            $this->registerPlugin($plugin, $identifier, $manifest);
            
            $this->loadedPlugins[$identifier] = [
                'instance' => $plugin,
                'manifest' => $manifest,
                'dependencies' => $dependencies
            ];

            Event::dispatch('plugin.loaded', ['identifier' => $identifier]);
            return true;

        } catch (\Exception $e) {
            $this->handlePluginLoadFailure($e, $identifier);
            throw new PluginException("Plugin load failed: {$identifier}", 0, $e);
        }
    }

    protected function executePluginMethod(string $identifier, string $method, array $params): mixed
    {
        if (!isset($this->loadedPlugins[$identifier])) {
            throw new PluginException('Plugin not loaded');
        }

        try {
            $plugin = $this->loadedPlugins[$identifier]['instance'];
            
            if (!method_exists($plugin, $method)) {
                throw new PluginException('Method not found');
            }

            $this->validateMethodCall($plugin, $method, $params);
            return $this->invokePluginMethod($plugin, $method, $params);

        } catch (\Exception $e) {
            $this->handlePluginExecutionFailure($e, $identifier, $method);
            throw new PluginException('Plugin execution failed', 0, $e);
        }
    }

    protected function validatePluginIdentifier(string $identifier): void
    {
        if (!$this->validator->validatePluginIdentifier($identifier)) {
            throw new PluginException('Invalid plugin identifier');
        }
    }

    protected function loadPluginManifest(string $identifier): array
    {
        $manifestPath = $this->getManifestPath($identifier);
        
        if (!file_exists($manifestPath)) {
            throw new PluginException('Plugin manifest not found');
        }

        $manifest = json_decode(file_get_contents($manifestPath), true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new PluginException('Invalid manifest format');
        }

        return $manifest;
    }

    protected function validatePluginManifest(array $manifest): void
    {
        if (!$this->validator->validatePluginManifest($manifest)) {
            throw new PluginException('Invalid plugin manifest');
        }

        if (!$this->validator->validatePluginSecurity($manifest)) {
            throw new SecurityException('Plugin security requirements not met');
        }
    }

    protected function resolveDependencies(array $dependencies): array
    {
        return $this->resolver->resolveDependencies($dependencies);
    }

    protected function instantiatePlugin(array $manifest, string $identifier): object
    {
        $className = $manifest['class'];
        
        if (!class_exists($className)) {
            throw new PluginException('Plugin class not found');
        }

        return $this->container->make($className);
    }

    protected function initializePlugin(object $plugin, array $manifest): void
    {
        if (method_exists($plugin, 'initialize')) {
            $plugin->initialize($manifest['config'] ?? []);
        }
    }

    protected function registerPlugin(object $plugin, string $identifier, array $manifest): void
    {
        foreach ($manifest['providers'] ?? [] as $provider) {
            $this->container->register($provider);
        }

        if (isset($manifest['bindings'])) {
            foreach ($manifest['bindings'] as $abstract => $concrete) {
                $this->container->bind($abstract, $concrete);
            }
        }

        $this->registerPluginCommands($manifest['commands'] ?? []);
        $this->registerPluginEvents($manifest['events'] ?? []);
    }

    protected function validateMethodCall(object $plugin, string $method, array $params): void
    {
        if (!$this->validator->validateMethodCall($plugin, $method, $params)) {
            throw new PluginException('Invalid method call');
        }

        if (!$this->hasPermission($plugin, $method)) {
            throw new SecurityException('Insufficient permissions');
        }
    }

    protected function invokePluginMethod(object $plugin, string $method, array $params): mixed
    {
        return $this->security->executeCriticalOperation(
            fn() => $plugin->$method(...$params),
            ['plugin_method' => $method]
        );
    }

    protected function registerPluginCommands(array $commands): void
    {
        foreach ($commands as $command) {
            $this->container->tag($command, 'plugin.commands');
        }
    }

    protected function registerPluginEvents(array $events): void
    {
        foreach ($events as $event => $listeners) {
            foreach ($listeners as $listener) {
                Event::listen($event, $listener);
            }
        }
    }

    protected function hasPermission(object $plugin, string $method): bool
    {
        $permissions = $this->config['method_permissions'][$plugin::class] ?? [];
        return in_array($method, $permissions);
    }

    protected function getManifestPath(string $identifier): string
    {
        return sprintf(
            '%s/%s/plugin.json',
            $this->config['plugins_path'],
            $identifier
        );
    }

    protected function handlePluginLoadFailure(\Exception $e, string $identifier): void
    {
        Log::error('Plugin load failed', [
            'plugin' => $identifier,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        Event::dispatch('plugin.load_failed', [
            'identifier' => $identifier,
            'error' => $e->getMessage()
        ]);
    }

    protected function handlePluginExecutionFailure(\Exception $e, string $identifier, string $method): void
    {
        Log::error('Plugin execution failed', [
            'plugin' => $identifier,
            'method' => $method,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        Event::dispatch('plugin.execution_failed', [
            'identifier' => $identifier,
            'method' => $method,
            'error' => $e->getMessage()
        ]);
    }
}
