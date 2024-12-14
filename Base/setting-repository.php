<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Setting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use App\Repositories\Interfaces\SettingRepositoryInterface;
use App\Exceptions\SettingNotFoundException;

class SettingRepository implements SettingRepositoryInterface
{
    /**
     * Cache prefix for settings
     */
    private const CACHE_PREFIX = 'settings:';

    /**
     * Cache TTL in seconds (1 hour)
     */
    private const CACHE_TTL = 3600;

    public function __construct(
        private readonly Setting $model
    ) {}

    /**
     * Get settings by group
     *
     * @param string $group
     * @return Collection
     */
    public function getByGroup(string $group): Collection
    {
        return Cache::remember(
            self::CACHE_PREFIX . "group:{$group}",
            self::CACHE_TTL,
            fn () => $this->model->where('group', $group)->get()
        );
    }

    /**
     * Get setting value with type casting
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function getValue(string $key, mixed $default = null): mixed
    {
        $setting = Cache::remember(
            self::CACHE_PREFIX . $key,
            self::CACHE_TTL,
            fn () => $this->model->where('key', $key)->first()
        );

        if (!$setting) {
            return $default;
        }

        return match ($setting->type) {
            'boolean' => (bool) $setting->value,
            'integer' => (int) $setting->value,
            'float' => (float) $setting->value,
            'json' => json_decode($setting->value, true),
            default => $setting->value,
        };
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
        $setting = $this->model->where('key', $key)->first();
        
        if (!$setting) {
            return false;
        }

        $result = $setting->update(['value' => $this->formatValue($value, $setting->type)]);
        
        if ($result) {
            $this->clearCache($key);
        }

        return $result;
    }

    /**
     * Bulk update settings
     *
     * @param array $values
     * @return bool
     */
    public function bulkUpdate(array $values): bool
    {
        $success = true;

        foreach ($values as $key => $value) {
            if (!$this->setValue($key, $value)) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * Create new setting
     *
     * @param array $data
     * @return Setting
     */
    public function createSetting(array $data): Setting
    {
        $setting = $this->model->create([
            'key' => $data['key'],
            'value' => $this->formatValue($data['value'], $data['type'] ?? 'string'),
            'type' => $data['type'] ?? 'string',
            'group' => $data['group'] ?? 'general',
            'description' => $data['description'] ?? null
        ]);

        $this->clearCache($setting->key);

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
        $result = $this->model->where('key', $key)->delete();
        
        if ($result) {
            $this->clearCache($key);
        }

        return (bool) $result;
    }

    /**
     * Get settings for export
     *
     * @param array $groups
     * @return Collection
     */
    public function getForExport(array $groups): Collection
    {
        return $this->model->whereIn('group', $groups)->get();
    }

    /**
     * Import settings
     *
     * @param array $settings
     * @param bool $overwrite
     * @return bool
     */
    public function import(array $settings, bool $overwrite = true): bool
    {
        foreach ($settings as $setting) {
            $existing = $this->model->where('key', $setting['key'])->first();

            if ($existing && !$overwrite) {
                continue;
            }

            if ($existing) {
                $existing->update($setting);
            } else {
                $this->model->create($setting);
            }

            $this->clearCache($setting['key']);
        }

        return true;
    }

    /**
     * Format value based on type
     *
     * @param mixed $value
     * @param string $type
     * @return string
     */
    private function formatValue(mixed $value, string $type): string
    {
        return match ($type) {
            'json' => json_encode($value),
            'boolean' => (string) (bool) $value,
            'integer' => (string) (int) $value,
            'float' => (string) (float) $value,
            default => (string) $value,
        };
    }

    /**
     * Clear cache for a setting
     *
     * @param string $key
     * @return void
     */
    private function clearCache(string $key): void
    {
        Cache::forget(self::CACHE_PREFIX . $key);
        
        // Clear group cache if exists
        $setting = $this->model->where('key', $key)->first();
        if ($setting) {
            Cache::forget(self::CACHE_PREFIX . "group:{$setting->group}");
        }
    }
}