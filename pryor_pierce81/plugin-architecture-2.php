<?php

namespace App\Core\Plugin;

use Illuminate\Support\Facades\{DB, Cache, Event};
use App\Core\Security\SecurityManager;
use App\Core\Validation\ValidationService;
use App\Core\Events\Plugin\{PluginActivated, PluginDeactivated};

class PluginManager implements PluginInterface
{
    protected SecurityManager $security;
    protected ValidationService $validator;
    protected PluginRepository $repository;
    protected DependencyResolver $resolver;
    protected PluginLoader $loader;
    protected array $config;
    private array $activePlugins = [];

    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        PluginRepository $repository,
        DependencyResolver $resolver,
        PluginLoader $loader,
        array $config
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->repository = $repository;
        $this->resolver = $resolver;
        $this->loader = $loader;
        $this->config = $config;
    }

    public function install(string $path): PluginEntity
    {
        return $this->security->executeCriticalOperation(function() use ($path) {
            return DB::transaction(function() use ($path) {
                $manifest = $this->loader->loadManifest($path);
                $this->validateManifest($manifest);
                
                $plugin = $this->repository->create([
                    'name' => $manifest['name'],
                    'version' => $manifest['version'],
                    'path' => $path,
                    'dependencies' => $manifest['dependencies'],
                    'status' => 'inactive'
                ]);

                $this->cache->tags(['plugins'])->flush();
                return $plugin;
            });
        });
    }

    public function activate(string $name): bool
    {
        return $this->security->executeCriticalOperation(function() use ($name) {
            return DB::transaction(function() use ($name) {
                $plugin = $this->repository->findByName($name);
                
                $this->validateDependencies($plugin);
                $this->validateSecurity($plugin);
                
                $instance = $this->loader->loadPlugin($plugin);
                $instance->activate();
                
                $this->repository->update($plugin->id, ['status' => 'active']);
                $this->activePlugins[$name] = $instance;
                
                Event::dispatch(new PluginActivated($plugin));
                $this->cache->tags(['plugins'])->flush();
                
                return true;
            });
        });
    }

    public function deactivate(string $name): bool
    {
        return $this->security->executeCriticalOperation(function() use ($name) {
            return DB::transaction(function() use ($name) {
                $plugin = $this->repository->findByName($name);
                
                $this->validateDeactivation($plugin);
                
                if (isset($this->activePlugins[$name])) {
                    $this->activePlugins[$name]->deactivate();
                    unset($this->activePlugins[$name]);
                }
                
                $this->repository->update($plugin->id, ['status' => 'inactive']);
                Event::dispatch(new PluginDeactivated($plugin));
                
                $this->cache->tags(['plugins'])->flush();
                return true;
            });
        });
    }

    public function uninstall(string $name): bool
    {
        return $this->security->executeCriticalOperation(function() use ($name) {
            return DB::transaction(function() use ($name) {
                $plugin = $this->repository->findByName($name);
                
                if ($plugin->status === 'active') {
                    $this->deactivate($name);
                }
                
                $this->loader->removePlugin($plugin);
                $result = $this->repository->delete($plugin->id);
                
                $this->cache->tags(['plugins'])->flush();
                return $result;
            });
        });
    }

    public function getActive(): array
    {
        return $this->cache->tags(['plugins'])->remember('plugins.active', function() {
            return $this->repository->findByStatus('active');
        });
    }

    public function executeHook(string $hook, array $params = []): array
    {
        return $this->security->executeCriticalOperation(function() use ($hook, $params) {
            $results = [];
            
            foreach ($this->activePlugins as $plugin) {
                if ($plugin->hasHook($hook)) {
                    $results[$plugin->getName()] = $plugin->executeHook($hook, $params);
                }
            }
            
            return $results;
        });
    }

    protected function validateManifest(array $manifest): void
    {
        $rules = [
            'name' => 'required|string|max:255|unique:plugins,name',
            'version' => 'required|string|max:50',
            'description' => 'string|max:1000',
            'author' => 'string|max:255',
            'license' => 'string|max:255',
            'dependencies' => 'array',
            'minimum_stability' => 'string|in:stable,beta,alpha'
        ];

        $this->validator->validate($manifest, $rules);
    }

    protected function validateDependencies(PluginEntity $plugin): void
    {
        $missing = $this->resolver->findMissingDependencies($plugin);
        
        if (!empty($missing)) {
            throw new PluginDependencyException('Missing dependencies: ' . implode(', ', $missing));
        }
    }

    protected function validateSecurity(PluginEntity $plugin): void
    {
        $scanner = new SecurityScanner($this->config['security_rules']);
        
        if (!$scanner->scanPlugin($plugin)) {
            throw new PluginSecurityException('Plugin failed security validation');
        }
    }

    protected function validateDeactivation(PluginEntity $plugin): void
    {
        $dependents = $this->resolver->findDependentPlugins($plugin);
        
        if (!empty($dependents)) {
            throw new PluginDeactivationException(
                'Cannot deactivate plugin. Required by: ' . implode(', ', $dependents)
            );
        }
    }
}
