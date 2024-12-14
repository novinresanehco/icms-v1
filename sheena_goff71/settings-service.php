<?php

namespace App\Core\Settings\Services;

use App\Core\Settings\Models\Setting;
use App\Core\Settings\Repositories\SettingRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Collection;

class SettingsService
{
    protected string $cachePrefix = 'settings:';
    protected int $cacheTTL = 86400; // 24 hours

    public function __construct(
        private SettingRepository $repository,
        private SettingValidator $validator
    ) {}

    public function get(string $key, $default = null)
    {
        return Cache::remember(
            $this->getCacheKey($key),
            $this->cacheTTL,
            fn() => $this->repository->getValue($key, $default)
        );
    }

    public function set(string $key, $value): Setting
    {
        $this->validator->validateSetting($key, $value);

        $setting = $this->repository->set($key, $value);
        Cache::forget($this->getCacheKey($key));
        
        return $setting;
    }

    public function delete(string $key): bool
    {
        $result = $this->repository->delete($key);
        Cache::forget($this->getCacheKey($key));
        
        return $result;
    }

    public function getMultiple(array $keys): Collection
    {
        return collect($keys)->mapWithKeys(function ($key) {
            return [$key => $this->get($key)];
        });
    }

    public function setMultiple(array $settings): Collection
    {
        $results = collect();

        foreach ($settings as $key => $value) {
            $results[$key] = $this->set($key, $value);
        }

        return $results;
    }

    public function getByGroup(string $group): Collection
    {
        return Cache::remember(
            $this->getCacheKey("group:{$group}"),
            $this->cacheTTL,
            fn() => $this->repository->getByGroup($group)
        );
    }

    public function all(): Collection
    {
        return Cache::remember(
            $this->getCacheKey('all'),
            $this->cacheTTL,
            fn() => $this->repository->all()
        );
    }

    public function clearCache(): void
    {
        Cache::flush();
    }

    protected function getCacheKey(string $key): string
    {
        return $this->cachePrefix . $key;
    }
}
