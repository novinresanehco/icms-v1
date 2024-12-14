<?php

namespace App\Core\Repositories;

use App\Models\Setting;
use Illuminate\Support\Collection;

class SettingRepository extends AdvancedRepository
{
    protected $model = Setting::class;

    public function set(string $key, $value, string $group = 'default'): void
    {
        $this->executeTransaction(function() use ($key, $value, $group) {
            $this->model->updateOrCreate(
                ['key' => $key, 'group' => $group],
                ['value' => serialize($value)]
            );
            
            $this->invalidateCache('get', $key, $group);
            $this->invalidateCache('getGroup', $group);
        });
    }

    public function get(string $key, string $group = 'default', $default = null)
    {
        return $this->executeWithCache(__METHOD__, function() use ($key, $group, $default) {
            $setting = $this->model
                ->where('key', $key)
                ->where('group', $group)
                ->first();

            return $setting ? unserialize($setting->value) : $default;
        }, $key, $group);
    }

    public function getGroup(string $group): Collection
    {
        return $this->executeWithCache(__METHOD__, function() use ($group) {
            return $this->model
                ->where('group', $group)
                ->get()
                ->mapWithKeys(function ($item) {
                    return [$item->key => unserialize($item->value)];
                });
        }, $group);
    }

    public function remove(string $key, string $group = 'default'): void
    {
        $this->executeTransaction(function() use ($key, $group) {
            $this->model
                ->where('key', $key)
                ->where('group', $group)
                ->delete();
            
            $this->invalidateCache('get', $key, $group);
            $this->invalidateCache('getGroup', $group);
        });
    }

    public function removeGroup(string $group): int
    {
        return $this->executeTransaction(function() use ($group) {
            $count = $this->model->where('group', $group)->delete();
            $this->invalidateCache('getGroup', $group);
            return $count;
        });
    }

    public function increment(string $key, string $group = 'default', int $amount = 1): int
    {
        return $this->executeTransaction(function() use ($key, $group, $amount) {
            $value = (int)$this->get($key, $group, 0) + $amount;
            $this->set($key, $value, $group);
            return $value;
        });
    }

    public function decrement(string $key, string $group = 'default', int $amount = 1): int
    {
        return $this->increment($key, $group, -$amount);
    }
}
