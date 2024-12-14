<?php

namespace App\Core\Repositories;

use App\Models\Setting;
use App\Core\Services\Cache\CacheService;

class SettingsRepository extends AdvancedRepository
{
    protected $model = Setting::class;
    protected $cache;

    public function __construct(CacheService $cache)
    {
        parent::__construct();
        $this->cache = $cache;
    }

    public function get(string $key, $default = null)
    {
        return $this->executeQuery(function() use ($key, $default) {
            return $this->cache->remember("setting.{$key}", function() use ($key, $default) {
                $setting = $this->model->where('key', $key)->first();
                return $setting ? $setting->value : $default;
            });
        });
    }

    public function set(string $key, $value): void
    {
        $this->executeTransaction(function() use ($key, $value) {
            $this->model->updateOrCreate(
                ['key' => $key],
                ['value' => $value]
            );
            $this->cache->forget("setting.{$key}");
        });
    }

    public function setMany(array $settings): void
    {
        $this->executeTransaction(function() use ($settings) {
            foreach ($settings as $key => $value) {
                $this->set($key, $value);
            }
        });
    }

    public function remove(string $key): void
    {
        $this->executeTransaction(function() use ($key) {
            $this->model->where('key', $key)->delete();
            $this->cache->forget("setting.{$key}");
        });
    }
}
