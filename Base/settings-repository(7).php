<?php

namespace App\Repositories;

use App\Models\Setting;
use App\Repositories\Contracts\SettingsRepositoryInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SettingsRepository implements SettingsRepositoryInterface
{
    protected $model;
    protected $cache;
    protected $cacheKey = 'cms_settings';
    protected $cacheDuration = 1440; // 24 hours

    public function __construct(Setting $model)
    {
        $this->model = $model;
        $this->cache = Cache::tags(['settings']);
    }

    public function get(string $key, $default = null)
    {
        $settings = $this->getAll();
        return $settings[$key] ?? $default;
    }

    public function set(string $key, $value)
    {
        return DB::transaction(function () use ($key, $value) {
            list($group, $name) = $this->parseKey($key);
            
            $setting = $this->model->updateOrCreate(
                ['key' => $key],
                [
                    'value' => $this->encodeValue($value),
                    'group' => $group,
                    'type' => $this->getValueType($value)
                ]
            );

            $this->clearCache();
            
            return $setting;
        });
    }

    public function setMany(array $settings)
    {
        return DB::transaction(function () use ($settings) {
            foreach ($settings as $key => $value) {
                $this->set($key, $value);
            }
            
            return $this->getAll();
        });
    }

    public function forget(string $key)
    {
        $this->model->where('key', $key)->delete();
        $this->clearCache();
    }

    public function has(string $key): bool
    {
        $settings = $this->getAll();
        return isset($settings[$key]);
    }

    public function all(): array
    {
        return $this->getAll();
    }

    public function getByGroup(string $group): array
    {
        $settings = $this->getAll();
        
        return array_filter($settings, function ($value, $key) use ($group) {
            list($settingGroup) = $this->parseKey($key);
            return $settingGroup === $group;
        }, ARRAY_FILTER_USE_BOTH);
    }

    public function flush()
    {
        $this->model->truncate();
        $this->clearCache();
    }

    public function flushGroup(string $group)
    {
        $this->model->where('group', $group)->delete();
        $this->clearCache();
    }

    public function getGroups(): array
    {
        return $this->model->select('group')
            ->distinct()
            ->pluck('group')
            ->toArray();
    }

    protected function getAll(): array
    {
        return $this->cache->remember($this->cacheKey, $this->cacheDuration, function () {
            return $this->model->all()
                ->mapWithKeys(function ($setting) {
                    return [$setting->key => $this->decodeValue($setting->value, $setting->type)];
                })
                ->toArray();
        });
    }

    protected function parseKey(string $key): array
    {
        $parts = explode('.', $key);
        return [
            $parts[0],
            count($parts) > 1 ? implode('.', array_slice($parts, 1)) : null
        ];
    }

    protected function getValueType($value): string
    {
        $type = gettype($value);
        
        switch ($type) {
            case 'boolean':
                return 'bool';
            case 'integer':
                return 'int';
            case 'double':
                return 'float';
            case 'array':
                return 'json';
            default:
                return 'string';
        }
    }

    protected function encodeValue($value)
    {
        if (is_array($value)) {
            return json_encode($value);
        }
        return $value;
    }

    protected function decodeValue($value, string $type)
    {
        switch ($type) {
            case 'bool':
                return (bool) $value;
            case 'int':
                return (int) $value;
            case 'float':
                return (float) $value;
            case 'json':
                return json_decode($value, true);
            default:
                return $value;
        }
    }

    protected function clearCache()
    {
        $this->cache->forget($this->cacheKey);
    }
}
