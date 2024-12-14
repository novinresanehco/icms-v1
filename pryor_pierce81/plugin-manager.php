<?php

namespace App\Core\Plugin;

use Illuminate\Support\Facades\{DB, Cache, Event};
use App\Core\Security\SecurityManager;
use App\Core\Services\{ValidationService, AuditService};
use App\Core\Interfaces\PluginManagerInterface;
use App\Core\Exceptions\{PluginException, ValidationException};

class PluginManager implements PluginManagerInterface
{
    private SecurityManager $security;
    private ValidationService $validator;
    private AuditService $audit;
    private array $config;
    private array $loadedPlugins = [];

    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        AuditService $audit,
        array $config
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->audit = $audit;
        $this->config = $config;
    }

    public function loadPlugin(string $identifier): bool
    {
        return $this->security->executeSecureOperation(
            fn() => $this->processPluginLoad($identifier),
            new SecurityContext('plugin.load', ['plugin' => $identifier])
        );
    }

    public function installPlugin(string $path): bool
    {
        return $this->security->executeSecureOperation(
            fn() => $this->processPluginInstall($path),
            new SecurityContext('plugin.install', ['path' => $path])
        );
    }

    public function uninstallPlugin(string $identifier): bool
    {
        return $this->security->executeSecureOperation(
            fn() => $this->processPluginUninstall($identifier),
            new SecurityContext('plugin.uninstall', ['plugin' => $identifier])
        );
    }

    protected function processPluginLoad(string $identifier): bool
    {
        try {
            if (isset($this->loadedPlugins[$identifier])) {
                return true;
            }

            $plugin = $this->getPluginData($identifier);
            $this->validatePlugin($plugin);

            if (!$this->checkDependencies($plugin)) {
                throw new PluginException('Plugin dependencies not met');
            }

            $instance = $this->createPluginInstance($plugin);
            $this->initializePlugin($instance, $plugin);
            
            $this->loadedPlugins[$identifier] = $instance;
            $this->audit->logPluginLoad($identifier);
            
            return true;

        } catch (\Exception $e) {
            $this->handlePluginFailure('load', $identifier, $e);
            throw new PluginException('Plugin load failed: ' . $e->getMessage());
        }
    }

    protected function processPluginInstall(string $path): bool
    {
        DB::beginTransaction();
        try {
            $pluginData = $this->extractPluginData($path);
            $this->validatePluginData($pluginData);

            if ($this->isPluginInstalled($pluginData['identifier'])) {
                throw new PluginException('Plugin already installed');
            }

            $this->verifyPluginSignature($path);
            $this->scanPluginSecurity($path);
            
            $this->installPluginFiles($path);
            $this->registerPluginDatabase($pluginData);
            
            $this->audit->logPluginInstall($pluginData['identifier']);
            
            DB::commit();
            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handlePluginFailure('install', $path, $e);
            throw new PluginException('Plugin installation failed: ' . $e->getMessage());
        }
    }

    protected function processPluginUninstall(string $identifier): bool
    {
        DB::beginTransaction();
        try {
            if (!$this->isPluginInstalled($identifier)) {
                throw new PluginException('Plugin not installed');
            }

            if ($this->hasActiveConnections($identifier)) {
                throw new PluginException('Plugin has active connections');
            }

            $this->deactivatePlugin($identifier);
            $this->removePluginFiles($identifier);
            $this->cleanPluginDatabase($identifier);
            
            $this->audit->logPluginUninstall($identifier);
            
            DB::commit();
            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handlePluginFailure('uninstall', $identifier, $e);
            throw new PluginException('Plugin uninstallation failed: ' . $e->getMessage());
        }
    }

    protected function validatePlugin(array $plugin): void
    {
        if (!$this->validator->validatePluginStructure($plugin)) {
            throw new ValidationException('Invalid plugin structure');
        }

        if (!$this->validator->validatePluginCompatibility($plugin)) {
            throw new ValidationException('Plugin compatibility check failed');
        }

        if (!$this->validator->validatePluginSecurity($plugin)) {
            throw new ValidationException('Plugin security validation failed');
        }
    }

    protected function validatePluginData(array $data): void
    {
        $rules = [
            'identifier' => 'required|string|max:255',
            'version' => 'required|string',
            'requirements' => 'array',
            'permissions' => 'array',
            'hooks' => 'array'
        ];

        if (!$this->validator->validate($data, $rules)) {
            throw new ValidationException('Invalid plugin data');
        }
    }

    protected function checkDependencies(array $plugin): bool
    {
        foreach ($plugin['dependencies'] as $dependency) {
            if (!$this->isDependencyMet($dependency)) {
                return false;
            }
        }
        return true;
    }

    protected function isDependencyMet(array $dependency): bool
    {
        $installedVersion = $this->getInstalledVersion($dependency['identifier']);
        return $installedVersion && version_compare($installedVersion, $dependency['version'], '>=');
    }

    protected function createPluginInstance(array $plugin): object
    {
        $className = $plugin['main_class'];
        return new $className($this->getPluginConfig($plugin));
    }

    protected function initializePlugin(object $instance, array $plugin): void
    {
        $instance->boot();
        $this->registerPluginHooks($instance, $plugin['hooks']);
        $this->setupPluginPermissions($plugin['permissions']);
    }

    protected function registerPluginHooks(object $instance, array $hooks): void
    {
        foreach ($hooks as $hook) {
            Event::listen($hook['event'], [$instance, $hook['method']]);
        }
    }

    protected function setupPluginPermissions(array $permissions): void
    {
        foreach ($permissions as $permission) {
            $this->registerPermission($permission);
        }
    }

    protected function verifyPluginSignature(string $path): void
    {
        $signature = $this->getPluginSignature($path);
        if (!$this->verifySignature($path, $signature)) {
            throw new SecurityException('Plugin signature verification failed');
        }
    }

    protected function scanPluginSecurity(string $path): void
    {
        $scanResult = $this->performSecurityScan($path);
        if (!$scanResult['safe']) {
            throw new SecurityException('Plugin security scan failed');
        }
    }

    protected function handlePluginFailure(string $operation, string $identifier, \Exception $e): void
    {
        $this->audit->logPluginFailure($operation, $identifier, [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        if ($this->isSecurityThreat($e)) {
            $this->security->handleSecurityThreat('plugin_failure', [
                'operation' => $operation,
                'plugin' => $identifier,
                'error' => $e->getMessage()
            ]);
        }
    }
}
