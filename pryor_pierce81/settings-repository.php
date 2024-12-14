<?php

namespace App\Core\Repository;

use App\Models\Setting;
use App\Core\Events\SettingEvents;
use App\Core\Exceptions\SettingRepositoryException;

class SettingRepository extends BaseRepository
{
    protected function getModelClass(): string
    {
        return Setting::class;
    }

    public function get(string $key, $default = null): mixed
    {
        return Cache::tags($this->getCacheTags())->remember(
            $this->getCacheKey('get', $key),
            $this->cacheTime,
            function() use ($key, $default) {
                $setting = $this->model->where('key', $key)->first();
                return $setting ? $setting->value : $default;
            }
        );
    }

    public function set(string $key, mixed $value): void
    {
        try {
            $setting = $this->model->firstOrNew(['key' => $key]);
            $setting->value = $value;
            $setting->save();

            $this->clearCache();
            event(new SettingEvents\SettingUpdated($setting));
        } catch (\Exception $e) {
            throw new SettingRepositoryException(
                "Failed to set setting: {$e->getMessage()}"
            );
        }
    }

    public function getMultiple(array $keys, array $defaults = []): array
    {
        return Cache::tags($this->getCacheTags())->remember(
            $this->getCacheKey('getMultiple', serialize($keys)),
            $this->cacheTime,
            function() use ($keys, $defaults) {
                $settings = $this->model->whereIn('key', $keys)->get()
                    ->pluck('value', 'key')->toArray();
                
                return array_merge($defaults, $settings);
            }
        );
    }

    public function setMultiple(array $settings): void
    {
        try {
            DB::transaction(function() use ($settings) {
                foreach ($settings as $key => $value) {
                    $setting = $this->model->firstOrNew(['key' => $key]);
                    $setting->value = $value;
                    $setting->save();
                }
            });

            $this->clearCache();
            event(new SettingEvents\SettingsUpdated($settings));
        } catch (\Exception $e) {
            throw new SettingRepositoryException(
                "Failed to set multiple settings: {$e->getMessage()}"
            );
        }
    }

    public function delete(string $key): void
    {
        try {
            $setting = $this->model->where('key', $key)->first();
            if ($setting) {
                $setting->delete();
                $this->clearCache();
                event(new SettingEvents\SettingDeleted($setting));
            }
        } catch (\Exception $e) {
            throw new SettingRepositoryException(
                "Failed to delete setting: {$e->getMessage()}"
            );
        }
    }
}
