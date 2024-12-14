<?php

namespace App\Repositories;

use App\Models\Setting;
use App\Repositories\Contracts\SettingRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SettingRepository extends BaseRepository implements SettingRepositoryInterface 
{
    protected array $searchableFields = ['key', 'description'];
    protected array $filterableFields = ['group', 'type'];

    /**
     * Get all settings by group
     *
     * @param string $group
     * @return Collection
     */
    public function getByGroup(string $group): Collection
    {
        return Cache::tags(['settings'])->remember("settings.group.{$group}", 3600, function() use ($group) {
            return $this->model->newQuery()
                ->where('group', $group)
                ->orderBy('sort_order')
                ->get()
                ->keyBy('key');
        });
    }

    /**
     * Get setting value by key
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function getValue(string $key, $default = null): mixed
    {
        $setting = Cache::tags(['settings'])->remember("settings.key.{$key}", 3600, function() use ($key) {
            return $this->model->where('key', $key)->first();
        });

        return $setting ? $this->castValue($setting->value, $setting->type) : $default;
    }

    /**
     * Set setting value
     *
     * @param string $key
     * @param mixed $value
     * @return bool
     */
    public function setValue(string $key, mixed $value): bool
    {
        try {
            $setting = $this->model->where('key', $key)->first();
            
            if (!$setting) {
                return false;
            }

            $setting->value = $this->prepareValue($value, $setting->type);
            $setting->save();

            Cache::tags(['settings'])->forget("settings.key.{$key}");
            Cache::tags(['settings'])->forget("settings.group.{$setting->group}");

            return true;
        } catch (\Exception $e) {
            \Log::error('Error setting value: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Bulk update settings
     *
     * @param array $settings
     * @return bool
     */
    public function bulkUpdate(array $settings): bool
    {
        try {
            DB::beginTransaction();

            foreach ($settings as $key => $value) {
                $setting = $this->model->where('key', $key)->first();
                if ($setting) {
                    $setting->value = $this->prepareValue($value, $setting->type);
                    $setting->save();
                }
            }

            DB::commit();
            Cache::tags(['settings'])->flush();

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Error bulk updating settings: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Create new setting
     *
     * @param array $data
     * @return Setting
     */
    public function createSetting(array $data): Setting
    {
        $setting = $this->create($data);
        Cache::tags(['settings'])->flush();
        return $setting;
    }

    /**
     * Delete setting
     *
     * @param string $key
     * @return bool
     */
    public function deleteSetting(string $key): bool
    {
        try {
            $setting = $this->model->where('key', $key)->first();
            
            if (!$setting) {
                return false;
            }

            $setting->delete();
            Cache::tags(['settings'])->flush();

            return true;
        } catch (\Exception $e) {
            \Log::error('Error deleting setting: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get settings for export
     *
     * @param array $groups
     * @return Collection
     */
    public function getForExport(array $groups = []): Collection
    {
        $query = $this->model->newQuery();

        if (!empty($groups)) {
            $query->whereIn('group', $groups);
        }

        return $query->orderBy('group')
            ->orderBy('sort_order')
            ->get()
            ->map(function($setting) {
                return [
                    'key' => $setting->key,
                    'value' => $setting->value,
                    'type' => $setting->type,
                    'group' => $setting->group,
                    'description' => $setting->description,
                    'sort_order' => $setting->sort_order
                ];
            });
    }

    /**
     * Import settings
     *
     * @param array $settings
     * @param bool $overwrite
     * @return bool
     */
    public function import(array $settings, bool $overwrite = false): bool
    {
        try {
            DB::beginTransaction();

            foreach ($settings as $settingData) {
                $existingSetting = $this->model->where('key', $settingData['key'])->first();

                if ($existingSetting) {
                    if ($overwrite) {
                        $existingSetting->update($settingData);
                    }
                } else {
                    $this->create($settingData);
                }
            }

            DB::commit();
            Cache::tags(['settings'])->flush();

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Error importing settings: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Cast value to appropriate type
     *
     * @param mixed $value
     * @param string $type
     * @return mixed
     */
    protected function castValue(mixed $value, string $type): mixed
    {
        return match($type) {
            'boolean' => (bool) $value,
            'integer' => (int) $value,
            'float' => (float) $value,
            'json' => json_decode($value, true),
            'array' => is_string($value) ? explode(',', $value) : $value,
            default => $value,
        };
    }

    /**
     * Prepare value for storage
     *
     * @param mixed $value
     * @param string $type
     * @return mixed
     */
    protected function prepareValue(mixed $value, string $type): mixed
    {
        return match($type) {
            'json' => is_string($value) ? $value : json_encode($value),
            'array' => is_array($value) ? implode(',', $value) : $value,
            default => $value,
        };
    }
}
