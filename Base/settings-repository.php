<?php

namespace App\Repositories;

use App\Models\Setting;
use App\Repositories\Contracts\SettingsRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class SettingsRepository extends BaseRepository implements SettingsRepositoryInterface
{
    protected array $searchableFields = ['key', 'description'];
    protected array $filterableFields = ['group', 'type'];

    public function get(string $key, $default = null)
    {
        $cacheKey = 'settings.' . $key;

        return Cache::tags(['settings'])->remember($cacheKey, 3600, function() use ($key, $default) {
            $setting = $this->model->where('key', $key)->first();
            return $setting ? $setting->value : $default;
        });
    }

    public function set(string $key, $value, string $group = 'general'): Setting
    {
        $setting = $this->model->updateOrCreate(
            ['key' => $key],
            [
                'value' => $value,
                'group' => $group
            ]
        );

        Cache::tags(['settings'])->flush();

        return $setting;
    }

    public function getGroup(string $group): Collection
    {
        $cacheKey = 'settings.group.' . $group;

        return Cache::tags(['settings'])->remember($cacheKey, 3600, function() use ($group) {
            return $this->model
                ->where('group', $group)
                ->orderBy('key')
                ->get()
                ->pluck('value', 'key');
        });
    }

    public function getAllGroups(): Collection
    {
        $cacheKey = 'settings.all_groups';

        return Cache::tags(['settings'])->remember($cacheKey, 3600, function() {
            return $this->model
                ->get()
                ->groupBy('group');
        });
    }

    public function delete(string $key): bool
    {
        try {
            $this->model->where('key', $key)->delete();
            Cache::tags(['settings'])->flush();
            return true;
        } catch (\Exception $e) {
            \Log::error('Error deleting setting: ' . $e->getMessage());
            return false;
        }
    }

    public function setMultiple(array $settings): bool
    {
        try {
            foreach ($settings as $key => $value) {
                $this->set($key, $value);
            }
            return true;
        } catch (\Exception $e) {
            \Log::error('Error setting multiple settings: ' . $e->getMessage());
            return false;
        }
    }

    public function reset(string $group = null): bool
    {
        try {
            $query = $this->model->newQuery();

            if ($group) {
                $query->where('group', $group);
            }

            $query->delete();
            Cache::tags(['settings'])->flush();

            return true;
        } catch (\Exception $e) {
            \Log::error('Error resetting settings: ' . $e->getMessage());
            return false;
        }
    }
}
