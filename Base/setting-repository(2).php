<?php

namespace App\Repositories;

use App\Models\Setting;
use App\Repositories\Contracts\SettingRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class SettingRepository extends BaseRepository implements SettingRepositoryInterface
{
    protected array $searchableFields = ['key', 'description'];
    protected array $filterableFields = ['group', 'type'];

    public function get(string $key, $default = null)
    {
        return Cache::tags(['settings'])->remember("setting.{$key}", 3600, function() use ($key, $default) {
            $setting = $this->model->where('key', $key)->first();
            return $setting ? $setting->value : $default;
        });
    }

    public function set(string $key, $value, string $group = 'general'): bool
    {
        try {
            $this->model->updateOrCreate(
                ['key' => $key],
                [
                    'value' => $value,
                    'group' => $group
                ]
            );
            
            Cache::tags(['settings'])->forget("setting.{$key}");
            return true;
        } catch (\Exception $e) {
            \Log::error('Error setting config value: ' . $e->getMessage());
            return false;
        }
    }

    public function getByGroup(string $group): Collection
    {
        return Cache::tags(['settings'])->remember("settings.group.{$group}", 3600, function() use ($group) {
            return $this->model
                ->where('group', $group)
                ->orderBy('key')
                ->get();
        });
    }

    public function deleteByKey(string $key): bool
    {
        try {
            $this->model->where('key', $key)->delete();
            Cache::tags(['settings'])->forget("setting.{$key}");
            return true;
        } catch (\Exception $e) {
            \Log::error('Error deleting setting: ' . $e->getMessage());
            return false;
        }
    }

    public function getAllAsArray(): array
    {
        return Cache::tags(['settings'])->remember('settings.all', 3600, function() {
            return $this->model
                ->pluck('value', 'key')
                ->toArray();
        });
    }
}
