<?php

namespace App\Core\Plugin;

class PluginManager
{
    private PluginRepository $repository;
    private PluginLoader $loader;
    private array $plugins = [];
    private array $hooks = [];

    public function register(Plugin $plugin): void
    {
        $this->validatePlugin($plugin);
        $this->plugins[$plugin->getId()] = $plugin;
        $this->registerHooks($plugin);
        $this->repository->save($plugin);
    }

    public function load(string $pluginId): void
    {
        $plugin = $this->repository->find($pluginId);
        if ($plugin && $plugin->isEnabled()) {
            $this->loader->load($plugin);
            $this->register($plugin);
        }
    }

    public function addHook(string $name, callable $callback): void
    {
        $this->hooks[$name][] = $callback;
    }

    public function executeHook(string $name, array $params = []): array
    {
        $results = [];
        if (isset($this->hooks[$name])) {
            foreach ($this->hooks[$name] as $callback) {
                $results[] = $callback($params);
            }
        }
        return $results;
    }

    private function validatePlugin(Plugin $plugin): void
    {
        if (isset($this->plugins[$plugin->getId()])) {
            throw new PluginException("Plugin already registered: {$plugin->getId()}");
        }

        $dependencies = $plugin->getDependencies();
        foreach ($dependencies as $dependency) {
            if (!isset($this->plugins[$dependency])) {
                throw new PluginException("Missing dependency: {$dependency}");
            }
        }
    }

    private function registerHooks(Plugin $plugin): void
    {
        foreach ($plugin->getHooks() as $hook) {
            $this->addHook($hook->getName(), $hook->getCallback());
        }
    }
}

class Plugin
{
    private string $id;
    private string $name;
    private string $version;
    private array $dependencies;
    private array $hooks;
    private bool $enabled;
    private array $config;

    public function __construct(
        string $id,
        string $name,
        string $version,
        array $dependencies = [],
        array $config = []
    ) {
        $this->id = $id;
        $this->name = $name;
        $this->version = $version;
        $this->dependencies = $dependencies;
        $this->hooks = [];
        $this->enabled = false;
        $this->config = $config;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
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

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function addHook(Hook $hook): void
    {
        $this->hooks[] = $hook;
    }

    public function enable(): void
    {
        $this->enabled = true;
    }

    public function disable(): void
    {
        $this->enabled = false;
    }
}

class Hook
{
    private string $name;
    private callable $callback;
    private int $priority;

    public function __construct(string $name, callable $callback, int $priority = 10)
    {
        $this->name = $name;
        $this->callback = $callback;
        $this->priority = $priority;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getCallback(): callable
    {
        return $this->callback;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }
}

class PluginLoader
{
    private string $pluginPath;
    private array $loadedPlugins = [];

    public function load(Plugin $plugin): void
    {
        if (isset($this->loadedPlugins[$plugin->getId()])) {
            return;
        }

        $this->loadDependencies($plugin);
        $this->requirePluginFile($plugin);
        $this->loadedPlugins[$plugin->getId()] = true;
    }

    private function loadDependencies(Plugin $plugin): void
    {
        foreach ($plugin->getDependencies() as $dependency) {
            if (!isset($this->loadedPlugins[$dependency])) {
                throw new PluginException("Dependency not loaded: {$dependency}");
            }
        }
    }

    private function requirePluginFile(Plugin $plugin): void
    {
        $file = $this->pluginPath . '/' . $plugin->getId() . '/plugin.php';
        if (!file_exists($file)) {
            throw new PluginException("Plugin file not found: {$file}");
        }
        require_once $file;
    }
}

class PluginRepository
{
    private $connection;

    public function save(Plugin $plugin): void
    {
        $this->connection->table('plugins')->updateOrInsert(
            ['id' => $plugin->getId()],
            [
                'name' => $plugin->getName(),
                'version' => $plugin->getVersion(),
                'dependencies' => json_encode($plugin->getDependencies()),
                'enabled' => $plugin->isEnabled(),
                'config' => json_encode($plugin->getConfig()),
                'updated_at' => now()
            ]
        );
    }

    public function find(string $id): ?Plugin
    {
        $row = $this->connection->table('plugins')
            ->where('id', $id)
            ->first();

        return $row ? $this->hydrate($row) : null;
    }

    public function findAll(): array
    {
        return $this->connection->table('plugins')
            ->get()
            ->map(fn($row) => $this->hydrate($row))
            ->toArray();
    }

    private function hydrate($row): Plugin
    {
        $plugin = new Plugin(
            $row->id,
            $row->name,
            $row->version,
            json_decode($row->dependencies, true),
            json_decode($row->config, true)
        );

        if ($row->enabled) {
            $plugin->enable();
        }

        return $plugin;
    }
}

class PluginException extends \Exception {}
