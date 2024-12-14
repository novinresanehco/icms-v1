<?php

namespace App\Repositories;

use App\Models\Plugin;
use App\Repositories\Contracts\PluginRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class PluginRepository extends BaseRepository implements PluginRepositoryInterface
{
    protected array $searchableFields = ['name', 'description', 'version'];
    protected array $filterableFields = ['status', 'type', 'is_core'];

    public function getActive(): Collection
    {
        return Cache::tags(['plugins'])->remember('plugins.active', 3600, function() {
            return $this->model
                ->where('status', 'active')
                ->where('is_enabled', true)
                ->orderBy('priority')
                ->get();
        });
    }

    public function install(array $data): Plugin
    {
        try {
            $plugin = $this->create([
                'name' => $data['name'],
                'description' => $data['description'],
                'version' => $data['version'],
                'provider_class' => $data['provider_class'],
                'requires' => $data['requires'] ?? [],
                'settings' => $data['settings'] ?? [],
                'status' => 'installed',
                'is_enabled' => false,
                'installed_at' => now()
            ]);

            Cache::tags(['plugins'])->flush();
            return $plugin;
        } catch (\Exception $e) {
            \Log::error('Error installing plugin: ' . $e->getMessage());
            throw $e;
        }
    }

    public function uninstall(int $id): bool
    {
        try {
            $plugin = $this->findById($id);
            
            // Run uninstall logic
            $providerClass = $plugin->provider_class;
            if (class_exists($providerClass)) {
                app($providerClass)->uninstall();
            }

            $result = $this->update($id, [
                'status' => 'uninstalled',
                'is_enabled' => false,
                'uninstalled_at' => now()
            ]);

            Cache::tags(['plugins'])->flush();
            return $result;
        } catch (\Exception $e) {
            \Log::error('Error uninstalling plugin: ' . $e->getMessage());
            return false;
        }
    }

    public function updateSettings(int $id, array $settings): bool
    {
        try {
            $plugin = $this->findById($id);
            $currentSettings = $plugin->settings;
            
            $updatedSettings = array_merge($currentSettings, $settings);
            
            $result = $this->update($id, [
                'settings' => $updatedSettings,
                'last_settings_update' => now()
            ]);

            Cache::tags(['plugins'])->flush();
            return $result;
        } catch (\Exception $e) {
            \Log::error('Error updating plugin settings: ' . $e->getMessage());
            return false;
        }
    }

    public function checkUpdates(Plugin $plugin): ?array
    {
        try {
            $updateChecker = app('App\Services\Plugin\UpdateChecker');
            return $updateChecker->check($plugin);
        } catch (\Exception $e) {
            \Log::error('Error checking plugin updates: ' . $e->getMessage());
            return null;
        }
    }
}
