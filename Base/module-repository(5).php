<?php

namespace App\Repositories;

use App\Models\Module;
use App\Core\Repositories\BaseRepository;
use Illuminate\Database\Eloquent\Collection;

class ModuleRepository extends BaseRepository
{
    public function __construct(Module $model)
    {
        $this->model = $model;
        parent::__construct();
    }

    public function findActive(): Collection
    {
        return $this->executeWithCache(__FUNCTION__, [], function () {
            return $this->model->where('status', 'active')
                             ->orderBy('priority')
                             ->get();
        });
    }

    public function install(string $name, array $config): Module
    {
        $module = $this->create([
            'name' => $name,
            'config' => $config,
            'version' => $config['version'] ?? '1.0.0',
            'status' => 'inactive',
            'priority' => $this->getNextPriority()
        ]);

        $this->clearCache();
        return $module;
    }

    public function uninstall(int $id): bool
    {
        $module = $this->find($id);
        if (!$module) {
            return false;
        }

        $result = $this->delete($id);
        $this->clearCache();
        return $result;
    }

    public function updateConfig(int $id, array $config): bool
    {
        $result = $this->update($id, ['config' => $config]);
        $this->clearCache();
        return $result;
    }

    public function updatePriorities(array $priorities): bool
    {
        foreach ($priorities as $id => $priority) {
            $this->update($id, ['priority' => $priority]);
        }
        
        $this->clearCache();
        return true;
    }

    protected function getNextPriority(): int
    {
        return $this->model->max('priority') + 10;
    }
}
