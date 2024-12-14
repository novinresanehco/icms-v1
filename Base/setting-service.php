<?php

namespace App\Core\Services;

use App\Core\Services\Contracts\SettingServiceInterface;
use App\Core\Repositories\Contracts\SettingRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class SettingService implements SettingServiceInterface
{
    public function __construct(
        private SettingRepositoryInterface $repository
    ) {}

    public function getSetting(string $key, $default = null)
    {
        return Cache::tags(['settings'])->remember(
            "settings.{$key}",
            now()->addDay(),
            fn() => $this->repository->get($key, $default)
        );
    }

    public function setSetting(string $key, $value): bool
    {
        $result = $this->repository->set($key, $value);
        Cache::tags(['settings'])->flush();
        return $result;
    }

    public function hasSetting(string $key): bool
    {
        return Cache::tags(['settings'])->remember(
            "settings.has.{$key}",
            now()->addDay(),
            fn() => $this->repository->has($key)
        );
    }

    public function removeSetting(string $key): bool
    {
        $result = $this->repository->remove($key);
        Cache::tags(['settings'])->flush();
        return $result;
    }

    public function getGroupSettings(string $group): Collection
    {
        return Cache::tags(['settings'])->remember(
            "settings.group.{$group}",
            now()->addDay(),
            fn() => $this->repository->getAllByGroup($group)
        );
    }

    public function getAllSettings(): Collection
    {
        return Cache::tags(['settings'])->remember(
            'settings.all',
            now()->addDay(),
            fn() => $this->repository->getAll()
        );
    }

    public function setManySettings(array $settings): bool
    {
        $result = $this->repository->setMany($settings);
        Cache::tags(['settings'])->flush();
        return $result;
    }

    public function removeManySettings(array $keys): bool
    {
        $result = $this->repository->removeMany($keys);
        Cache::tags(['settings'])->flush();
        return $result;
    }

    public function removeGroupSettings(string $group): bool
    {
        $result = $this->repository->removeByGroup($group);
        Cache::tags(['settings'])->flush();
        return $result;
    }
}
