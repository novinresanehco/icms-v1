<?php

namespace App\Repositories;

use App\Models\Module;
use App\Repositories\Contracts\ModuleRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class ModuleRepository extends BaseRepository implements ModuleRepositoryInterface
{
    protected array $searchableFields = ['name', 'description', 'version'];
    protected array $filterableFields = ['status', 'type', 'is_core'];

    public function getActive(): Collection
    {
        return Cache::tags(['modules'])->remember('modules.active', 3600, function() {
            return $this->model
                ->where('status', 'active')
                ->where('is_enabled', true)
                ->orderBy('priority')
                ->get();
        });
    }

    public function install(array $data): Module
    {
        try {
            $module = $this->create([
                'name' => $data['name'],
                'description' => $data['description'] ?? '',
                'version' => $data['version'],
                'provider_class' => $data['provider_class'],
                'is_core' => $data['is_core'] ?? false,
                'requires' => $data['requires'] ?? [],
                'settings' => $data['settings'] ?? [],
                'priority' => $data['priority'] ?? 0,
                'status' => 'inactive',
                'is_enabled' => false
            ]);

            Cache::tags(['modules'])->flush();
            return $module;
        } catch (\Exception $e) {
            \Log::error('Error installing module: ' . $e->getMessage());
            throw $e;
        }
    }

    public function updateStatus(int $id, string $status, bool $enabled = true): bool
    {
        try {
            $result = $this->update($id, [
                'status' => $status,
                'is_enabled' => $enabled,
                'last_status_change' => now()
            ]);
            
            Cache::tags(['modules'])->flush();
            return $result;
        } catch (\Exception $e) {
            \Log::error('Error updating module status: ' . $e->getMessage());
            return false;
        }
    }

    public function checkDependencies(Module $module): array
    {
        $missing = [];
        $incompatible = [];

        foreach ($module->requires as $requirement) {
            $requiredModule = $this->model
                ->where('name', $requirement['name'])
                ->first();

            if (!$requiredModule) {
                $missing[] = $requirement['name'];
                continue;
            }

            if (version_compare($requiredModule->version, $requirement['version'], '<')) {
                $incompatible[] = [
                    'name' => $requirement['name'],
                    'required' => $requirement['version'],
                    'installed' => $requiredModule->version
                ];
            }
        }

        return [
            'missing' => $missing,
            'incompatible' => $incompatible,
            'is_satisfied' => empty($missing) && empty($incompatible)
        ];
    }
}
