<?php

namespace App\Core\Plugin\Models;

use Illuminate\Database\Eloquent\Model;

class Plugin extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'version',
        'author',
        'status',
        'config',
        'metadata'
    ];

    protected $casts = [
        'config' => 'array',
        'metadata' => 'array',
        'activated_at' => 'datetime'
    ];
}

namespace App\Core\Plugin\Contracts;

interface PluginInterface
{
    public function register(): void;
    public function boot(): void;
    public function activate(): void;
    public function deactivate(): void;
    public function install(): void;
    public function uninstall(): void;
}

namespace App\Core\Plugin;

abstract class BasePlugin implements PluginInterface
{
    protected string $name;
    protected string $description;
    protected string $version;
    protected array $dependencies = [];
    protected array $hooks = [];

    public function register(): void {}
    public function boot(): void {}
    public function activate(): void {}
    public function deactivate(): void {}
    public function install(): void {}
    public function uninstall(): void {}

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getDependencies(): array
    {
        return $this->dependencies;
    }

    public function getHooks(): array
    {
        return $this->hooks;
    }
}

namespace App\Core\Plugin\Services;

use App\Core\Plugin\Exceptions\PluginException;
use App\Core\Plugin\Repositories\PluginRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class PluginService
{
    private PluginRepository $repository;
    private PluginLoader $loader;
    private DependencyResolver $resolver;

    public function __construct(
        PluginRepository $repository,
        PluginLoader $loader,
        DependencyResolver $resolver
    ) {
        $this->repository = $repository;
        $this->loader = $loader;
        $this->resolver = $resolver;
    }

    public function install(string $pluginPath): Plugin
    {
        try {
            DB::beginTransaction();

            $plugin = $this->loader->load($pluginPath);
            $this->resolver->checkDependencies($plugin);

            $pluginInstance = $this->createPluginInstance($plugin);
            $pluginInstance->install();

            $result = $this->repository->create([
                'name' => $plugin->getName(),
                'slug' => \Str::slug($plugin->getName()),
                'description' => $plugin->getDescription(),
                'version' => $plugin->getVersion(),
                'status' => 'installed',
                'metadata' => [
                    'dependencies' => $plugin->getDependencies(),
                    'hooks' => $plugin->getHooks()
                ]
            ]);

            DB::commit();
            return $result;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new PluginException("Plugin installation failed: {$e->getMessage()}");
        }
    }

    public function activate(int $pluginId): void
    {
        try {
            DB::beginTransaction();

            $plugin = $this->repository->find($pluginId);
            $instance = $this->loader->loadFromDatabase($plugin);
            
            $this->resolver->checkDependencies($instance);
            $instance->activate();

            $plugin->update([
                'status' => 'active',
                'activated_at' => now()
            ]);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw new PluginException("Plugin activation failed: {$e->getMessage()}");
        }
    }

    public function deactivate(int $pluginId): void
    {
        try {
            DB::beginTransaction();

            $plugin = $this->repository->find($pluginId);
            $instance = $this->loader->loadFromDatabase($plugin);
            
            $this->resolver->checkDependents($plugin);
            $instance->deactivate();

            $plugin->update([
                'status' => 'inactive',
                'activated_at' => null
            ]);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw new PluginException("Plugin deactivation failed: {$e->getMessage()}");
        }
    }

    public function uninstall(int $pluginId): void
    {
        try {
            DB::beginTransaction();

            $plugin = $this->repository->find($pluginId);
            $instance = $this->loader->loadFromDatabase($plugin);
            
            $this->resolver->checkDependents($plugin);
            $instance->uninstall();

            $this->repository->delete($pluginId);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw new PluginException("Plugin uninstallation failed: {$e->getMessage()}");
        }
    }
}

class PluginLoader
{
    private const PLUGIN_NAMESPACE = 'App\\Plugins\\';

    public function load(string $path): PluginInterface
    {
        if (!File::exists($path)) {
            throw new PluginException("Plugin path does not exist: {$path}");
        }

        $config = require "{$path}/plugin.php";
        $className = self::PLUGIN_NAMESPACE . $config['class'];

        if (!class_exists($className)) {
            throw new PluginException("Plugin class not found: {$className}");
        }

        return new $className();
    }

    public function loadFromDatabase(Plugin $plugin): PluginInterface
    {
        $className = self::PLUGIN_NAMESPACE . \Str::studly($plugin->slug);

        if (!class_exists($className)) {
            throw new PluginException("Plugin class not found: {$className}");
        }

        return new $className();
    }
}

class DependencyResolver
{
    private PluginRepository $repository;

    public function __construct(PluginRepository $repository)
    {
        $this->repository = $repository;
    }

    public function checkDependencies(PluginInterface $plugin): void
    {
        foreach ($plugin->getDependencies() as $dependency => $version) {
            $dependencyPlugin = $this->repository->findBySlug($dependency);

            if (!$dependencyPlugin) {
                throw new PluginException("Required plugin not installed: {$dependency}");
            }

            if ($dependencyPlugin->status !== 'active') {
                throw new PluginException("Required plugin not active: {$dependency}");
            }

            if (!$this->checkVersion($dependencyPlugin->version, $version)) {
                throw new PluginException("Plugin version mismatch: {$dependency} ({$version} required)");
            }
        }
    }

    public function checkDependents(Plugin $plugin): void
    {
        $dependents = $this->repository->findDependents($plugin->slug);

        if ($dependents->isNotEmpty()) {
            $names = $dependents->pluck('name')->implode(', ');
            throw new PluginException("Plugin cannot be deactivated/uninstalled. Required by: {$names}");
        }
    }

    private function checkVersion(string $actual, string $required): bool
    {
        return version_compare($actual, $required, '>=');
    }
}

namespace App\Core\Plugin\Http\Controllers;

use App\Core\Plugin\Services\PluginService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PluginController extends Controller
{
    private PluginService $pluginService;

    public function __construct(PluginService $pluginService)
    {
        $this->pluginService = $pluginService;
    }

    public function install(Request $request): JsonResponse
    {
        try {
            $request->validate(['path' => 'required|string']);
            $plugin = $this->pluginService->install($request->input('path'));
            return response()->json($plugin, 201);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function activate(int $id): JsonResponse
    {
        try {
            $this->pluginService->activate($id);
            return response()->json(['message' => 'Plugin activated successfully']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function deactivate(int $id): JsonResponse
    {
        try {
            $this->pluginService->deactivate($id);
            return response()->json(['message' => 'Plugin deactivated successfully']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function uninstall(int $id): JsonResponse
    {
        try {
            $this->pluginService->uninstall($id);
            return response()->json(['message' => 'Plugin uninstalled successfully']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }
}
