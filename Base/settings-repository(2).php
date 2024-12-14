<?php

namespace App\Repositories;

use App\Repositories\Contracts\SettingsRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class SettingsRepository implements SettingsRepositoryInterface
{
    protected string $table = 'settings';
    protected string $cacheKey = 'cms_settings';
    protected int $cacheDuration = 86400; // 24 hours

    /**
     * Get all settings
     *
     * @param bool $useCache Whether to use cached values
     * @return Collection
     */
    public function all(bool $useCache = true): Collection
    {
        if ($useCache) {
            return Cache::remember($this->cacheKey, $this->cacheDuration, function() {
                return $this->fetchAllSettings();
            });
        }

        return $this->fetchAllSettings();
    }

    /**
     * Get setting by key
     *
     * @param string $key
     * @param mixed $default Default value if setting not found
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $settings = $this->all();
        $setting = $settings->firstWhere('key', $key);

        if (!$setting) {
            return $default;
        }

        return $this->castValue($setting['value'], $setting['type']);
    }

    /**
     * Get settings by group
     *
     * @param string $group
     * @return Collection
     */
    public function getGroup(string $group): Collection
    {
        return $this->all()->filter(function($setting) use ($group) {
            return $setting['group'] === $group;
        });
    }

    /**
     * Set setting value
     *
     * @param string $key
     * @param mixed $value
     * @param string $type Value type (string, integer, float, boolean, json)
     * @param string|null $group Optional grouping
     * @return bool
     */
    public function set(string $key, mixed $value, string $type = 'string', ?string $group = null): bool
    {
        try {
            DB::beginTransaction();

            $exists = DB::table($this->table)->where('key', $key)->exists();
            $serializedValue = $this->serializeValue($value, $type);

            if ($exists) {
                $updated = DB::table($this->table)
                    ->where('key', $key)
                    ->update([
                        'value' => $serializedValue,
                        'type' => $type,
                        'updated_at' => now()
                    ]) > 0;
            } else {
                $updated = DB::table($this->table)->insert([
                    'key' => $key,
                    'value' => $serializedValue,
                    'type' => $type,
                    'group' => $group,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            }

            if ($updated) {
                $this->clearCache();
            }

            DB::commit();
            return $updated;
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Failed to set setting: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Set multiple settings at once
     *
     * @param array $settings Array of settings with keys: key, value, type, group
     * @return bool
     */
    public function setMany(array $settings): bool
    {
        try {
            DB::beginTransaction();

            foreach ($settings as $setting) {
                if (!isset($setting['key']) || !isset($setting['value'])) {
                    throw new \InvalidArgumentException('Missing required setting fields');
                }

                $this->set(
                    $setting['key'],
                    $setting['value'],
                    $setting['type'] ?? 'string',
                    $setting['group'] ?? null
                );
            }

            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Failed to set multiple settings: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete setting
     *
     * @param string $key
     * @return bool
     */
    public function delete(string $key): bool
    {
        try {
            $deleted = DB::table($this->table)
                ->where('key', $key)
                ->delete() > 0;

            if ($deleted) {
                $this->clearCache();
            }

            return $deleted;
        } catch (\Exception $e) {
            \Log::error('Failed to delete setting: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete settings by group
     *
     * @param string $group
     * @return bool
     */
    public function deleteGroup(string $group): bool
    {
        try {
            $deleted = DB::table($this->table)
                ->where('group', $group)
                ->delete() > 0;

            if ($deleted) {
                $this->clearCache();
            }

            return $deleted;
        } catch (\Exception $e) {
            \Log::error('Failed to delete settings group: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Clear settings cache
     *
     * @return void
     */
    public function clearCache(): void
    {
        Cache::forget($this->cacheKey);
    }

    /**
     * Fetch all settings from database
     *
     * @return Collection
     */
    protected function fetchAllSettings(): Collection
    {
        return collect(DB::table($this->table)->get());
    }

    /**
     * Cast value to appropriate type
     *
     * @param string $value
     * @param string $type
     * @return mixed
     */
    protected function castValue(string $value, string $type): mixed
    {
        return match ($type) {
            'integer' => (int) $value,
            'float' => (float) $value,
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'json' => json_decode($value, true),
            default => $value
        };
    }

    /**
     * Serialize value for storage
     *
     * @param mixed $value
     * @param string $type
     * @return string
     */
    protected function serializeValue(mixed $value, string $type): string
    {
        return match ($type) {
            'json' => json_encode($value),
            'boolean' => $value ? '1' : '0',
            default => (string) $value
        };
    }
}
