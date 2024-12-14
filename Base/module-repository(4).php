<?php

namespace App\Core\Repositories;

use App\Models\Module;
use App\Core\Services\Cache\CacheService;
use Illuminate\Support\Collection;

class ModuleRepository extends AdvancedRepository
{
    protected $model = Module::class;
    protected $cache;

    public function __construct(CacheService $cache)
    {
        parent::__construct();
        $this->cache = $cache;
    }

    public function getActive(): Collection
    {
        return $this->executeQuery(function() {
            return $this->cache->remember('modules.active', function() {
                return $this->model
                    ->where('active', true)
                    ->where('installed', true)
                    ->orderBy('order')
                    ->get();
            });
        });
    }

    public function getInstalled(): Collection
    {
        return $this->executeQuery(function() {
            return $this->cache->remember('modules.installed', function() {
                return $this->model
                    ->where('installed', true)
                    ->orderBy('name')
                    ->get();
            });
        });
    }

    public function install(string $name, array $config = []): Module
    {
        return $this->executeTransaction(function() use ($name, $config) {
            $module = $this->create([
                'name' => $name,
                'config' => $config,
                'installed' => true,
                'active' => true,
                'installed_at' => now()
            ]);
            
            $this->cache->tags('modules')->flush();
            return $module;
        });
    }

    public function uninstall(Module $module): void
    {
        $this->executeTransaction(function() use ($module) {
            $module->update([
                'installed' => false,
                'active' => false,
                'config' => [],
                'uninstalled_at' => now()
            ]);
            
            $this->cache->tags('modules')->flush();
        });
    }

    public function updateConfig(Module $module, array $config): void
    {
        $this->executeTransaction(function() use ($module, $config) {
            $module->update([
                'config' => array_merge($module->config, $config)
            ]);
            
            $this->cache->tags('modules')->flush();
        });
    }
}
