<?php

namespace App\Core\Plugin;

use App\Core\Plugin\Contracts\PluginHookInterface;
use App\Core\Plugin\Exceptions\HookException;
use Illuminate\Support\Collection;

class HookManager
{
    private Collection $hooks;

    public function __construct()
    {
        $this->hooks = new Collection();
    }

    /**
     * Register a new hook
     *
     * @param string $name
     * @param PluginHookInterface $hook
     */
    public function register(string $name, PluginHookInterface $hook): void
    {
        if (!$this->hooks->has($name)) {
            $this->hooks->put($name, new Collection());
        }

        $this->hooks->get($name)->push($hook);
        
        // Sort hooks by priority
        $this->hooks->get($name)->sortBy(function ($hook) {
            return $hook->getPriority();
        });
    }

    /**
     * Execute hooks for a given name
     *
     * @param string $name
     * @param array $params
     * @return array Results from all hooks
     * @throws HookException
     */
    public function execute(string $name, array $params = []): array
    {
        if (!$this->hooks->has($name)) {
            return [];
        }

        $results = [];

        foreach ($this->hooks->get($name) as $hook) {
            try {
                $results[] = $hook->execute($params);
            } catch (\Exception $e) {
                throw new HookException(
                    "Failed to execute hook {$name}: {$e->getMessage()}"
                );
            }
        }

        return $results;
    }

    /**
     * Remove all hooks for a given name
     *
     * @param string $name
     */
    public function remove(string $name): void
    {
        $this->hooks->forget($name);
    }

    /**
     * Check if hooks exist for a given name
     *
     * @param string $name
     * @return bool
     */
    public function has(string $name): bool
    {
        return $this->hooks->has($name) && 
               $this->hooks->get($name)->isNotEmpty();
    }

    /**
     * Get all registered hooks
     *
     * @return Collection
     */
    public function all(): Collection
    {
        return $this->hooks;
    }

    /**
     * Get hooks for a given name
     *
     * @param string $name
     * @return Collection
     */
    public function get(string $name): Collection
    {
        return $this->hooks->get($name, new Collection());
    }
}
