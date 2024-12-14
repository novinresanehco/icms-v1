<?php

namespace App\Repositories;

use App\Models\Setting;
use App\Core\Repositories\BaseRepository;
use Illuminate\Support\Collection;

class SettingRepository extends BaseRepository
{
    public function __construct(Setting $model)
    {
        $this->model = $model;
        parent::__construct();
    }

    public function get(string $key, $default = null): mixed
    {
        return $this->executeWithCache(__FUNCTION__, [$key], function () use ($key, $default) {
            $setting = $this->model->where('key', $key)->first();
            return $setting ? $setting->value : $default;
        });
    }

    public function set(string $key, mixed $value): bool
    {
        $setting = $this->model->firstOrNew(['key' => $key]);
        $setting->value = $value;
        $result = $setting->save();
        
        $this->clearCache();
        return $result;
    }

    public function getGroup(string $group): Collection
    {
        return $this->executeWithCache(__FUNCTION__, [$group], function () use ($group) {
            return $this->model->where('key', 'LIKE', $group . '.%')
                             ->get()
                             ->pluck('value', 'key');
        });
    }

    public function setGroup(string $group, array $values): bool
    {
        foreach ($values as $key => $value) {
            $this->set($group . '.' . $key, $value);
        }
        
        $this->clearCache();
        return true;
    }

    public function remove(string $key): bool
    {
        $result = $this->model->where('key', $key)->delete();
        $this->clearCache();
        return $result;
    }
}
