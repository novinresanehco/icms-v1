<?php

namespace App\Core\Plugin;

use Illuminate\Support\Facades\{DB, Cache};

class PluginManager
{
    private SecurityManager $security;
    private PluginRepository $repository;
    private PluginValidator $validator;
    private PluginLoader $loader;
    private EventDispatcher $events;

    public function __construct(
        SecurityManager $security,
        PluginRepository $repository,
        PluginValidator $validator,
        PluginLoader $loader,
        EventDispatcher $events
    ) {
        $this->security = $security;
        $this->repository = $repository;
        $this->validator = $validator;
        $this->loader = $loader;
        $this->events = $events;
    }

    public function install(string $pluginPath): Plugin
    {
        return $this->security->protectedExecute(function() use ($pluginPath) {
            $manifest = $this->loader->loadManifest($pluginPath);
            $this->validator->validateManifest($manifest);
            
            DB::beginTransaction();
            try {
                $plugin = $this->repository->create($manifest);
                $this->loader->install($plugin, $pluginPath);
                
                $this->events->dispatch(new PluginInstalled($plugin));
                DB::commit();
                
                return $plugin;
            } catch (\Exception $e) {
                DB::rollBack();
                $this->loader->cleanup($pluginPath);
                throw $e;
            }
        });
    }

    public function activate(string $identifier): void
    {
        $this->security->protectedExecute(function() use ($identifier) {
            $plugin = $this->repository->findByIdentifier($identifier);
            if (!$plugin) {
                throw new PluginNotFoundException($identifier);
            }

            if (!$this->validator->canActivate($plugin)) {
                throw new PluginValidationException('Plugin cannot be activated');
            }

            DB::transaction(function() use ($plugin) {
                $this->repository->activate($plugin->id);
                $this->loader->load($plugin);
                $this->events->dispatch(new PluginActivated($plugin));
            });
        });
    }

    public function deactivate(string $identifier): void
    {
        $this->security->protectedExecute(function() use ($identifier) {
            $plugin = $this->repository->findByIdentifier($identifier);
            if (!$plugin) {
                throw new PluginNotFoundException($identifier);
            }

            DB::transaction(function() use ($plugin) {
                $this->repository->deactivate($plugin->id);
                $this->loader->unload($plugin);
                $this->events->dispatch(new PluginDeactivated($plugin));
            });
        });
    }

    public function uninstall(string $identifier): void
    {
        $this->security->protectedExecute(function() use ($identifier) {
            $plugin = $this->repository->findByIdentifier($identifier);
            if (!$plugin) {
                throw new PluginNotFoundException($identifier);
            }

            DB::transaction(function() use ($plugin) {
                $this->loader->uninstall($plugin);
                $this->repository->delete($plugin->id);
                $this->events->dispatch(new PluginUninstalled($plugin));
            });
        });
    }
}

class PluginValidator
{
    private DependencyResolver $dependencies;
    private SecurityScanner $security;

    public function validateManifest(PluginManifest $manifest): void
    {
        $this->validateIdentifier($manifest->identifier);
        $this->validateVersion($manifest->version);
        $this->validateDependencies($manifest->dependencies);
        $this->validatePermissions($manifest->permissions);
    }

    public function canActivate(Plugin $plugin): bool
    {
        return $this->validateDependencies($plugin->dependencies)
            && $this->security->scanPlugin($plugin)
            && $this->validateSystemRequirements($plugin);
    }

    private function validateIdentifier(string $identifier): void
    {
        if (!preg_match('/^[a-zA-Z0-9\-_.]+$/', $identifier)) {
            throw new PluginValidationException('Invalid plugin identifier');
        }
    }

    private function validateVersion(string $version): void
    {
        if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) {
            throw new PluginValidationException('Invalid version format');
        }
    }

    private function validateDependencies(array $dependencies): bool
    {
        return $this->dependencies->checkDependencies($dependencies);
    }

    private function validatePermissions(array $permissions): void
    {
        foreach ($permissions as $permission) {
            if (!$this->security->isPermissionAllowed($permission)) {
                throw new PluginValidationException("Permission not allowed: $permission");
            }
        }
    }

    private function validateSystemRequirements(Plugin $plugin): bool
    {
        $requirements = $plugin->getSystemRequirements();
        
        foreach ($requirements as $requirement => $value) {
            if (!$this->checkRequirement($requirement, $value)) {
                return false;
            }
        }
        
        return true;
    }

    private function checkRequirement(string $requirement, $value): bool
    {
        // Implement system requirement validation
        return true;
    }
}

class PluginLoader
{
    private string $pluginPath;
    private Filesystem $files;

    public function loadManifest(string $path): PluginManifest
    {
        if (!$this->files->exists($path . '/manifest.json')) {
            throw new PluginValidationException('Missing manifest file');
        }

        $manifest = json_decode(
            $this->files->get($path . '/manifest.json'),
            true
        );

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new PluginValidationException('Invalid manifest format');
        }

        return new PluginManifest($manifest);
    }

    public function install(Plugin $plugin, string $source): void
    {
        $destination = $this->getPluginPath($plugin->identifier);
        
        $this->files->ensureDirectoryExists($destination);
        $this->files->copyDirectory($source, $destination);
    }

    public function load(Plugin $plugin): void
    {
        $path = $this->getPluginPath($plugin->identifier);
        
        if (!$this->files->exists($path . '/bootstrap.php')) {
            throw new PluginException('Missing bootstrap file');
        }

        require_once $path . '/bootstrap.php';
    }

    public function unload(Plugin $plugin): void
    {
        // Implement plugin unloading logic
    }

    public function uninstall(Plugin $plugin): void
    {
        $path = $this->getPluginPath($plugin->identifier);
        $this->files->deleteDirectory($path);
    }

    public function cleanup(string $path): void
    {
        if ($this->files->exists($path)) {
            $this->files->deleteDirectory($path);
        }
    }

    private function getPluginPath(string $identifier): string
    {
        return $this->pluginPath . '/' . $identifier;
    }
}

class PluginRepository
{
    public function create(PluginManifest $manifest): Plugin
    {
        $id = DB::table('plugins')->insertGetId([
            'identifier' => $manifest->identifier,
            'version' => $manifest->version,
            'name' => $manifest->name,
            'description' => $manifest->description,
            'dependencies' => json_encode($manifest->dependencies),
            'permissions' => json_encode($manifest->permissions),
            'active' => false
        ]);

        return $this->find($id);
    }

    public function find(int $id): ?Plugin
    {
        $data = DB::table('plugins')->find($id);
        return $data ? new Plugin((array)$data) : null;
    }

    public function findByIdentifier(string $identifier): ?Plugin
    {
        $data = DB::table('plugins')
            ->where('identifier', $identifier)
            ->first();
        
        return $data ? new Plugin((array)$data) : null;
    }

    public function activate(int $id): void
    {
        DB::table('plugins')->where('id', $id)->update(['active' => true]);
    }

    public function deactivate(int $id): void
    {
        DB::table('plugins')->where('id', $id)->update(['active' => false]);
    }

    public function delete(int $id): void
    {
        DB::table('plugins')->delete($id);
    }
}

class Plugin
{
    public readonly int $id;
    public readonly string $identifier;
    public readonly string $version;
    public readonly string $name;
    public readonly string $description;
    public readonly array $dependencies;
    public readonly array $permissions;
    public readonly bool $active;

    public function __construct(array $attributes)
    {
        $this->id = $attributes['id'];
        $this->identifier = $attributes['identifier'];
        $this->version = $attributes['version'];
        $this->name = $attributes['name'];
        $this->description = $attributes['description'];
        $this->dependencies = json_decode($attributes['dependencies'], true);
        $this->permissions = json_decode($attributes['permissions'], true);
        $this->active = (bool)$attributes['active'];
    }

    public function getSystemRequirements(): array
    {
        // Implement system requirements retrieval
        return [];
    }
}

class PluginManifest
{
    public readonly string $identifier;
    public readonly string $version;
    public readonly string $name;
    public readonly string $description;
    public readonly array $dependencies;
    public readonly array $permissions;

    public function __construct(array $data)
    {
        $this->identifier = $data['identifier'];
        $this->version = $data['version'];
        $this->name = $data['name'];
        $this->description = $data['description'] ?? '';
        $this->dependencies = $data['dependencies'] ?? [];
        $this->permissions = $data['permissions'] ?? [];
    }
}

class PluginException extends \Exception {}
class PluginValidationException extends PluginException {}
class PluginNotFoundException extends PluginException {}
