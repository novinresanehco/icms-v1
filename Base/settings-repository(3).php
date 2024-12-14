<?php

namespace App\Repositories;

use App\Models\Setting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use App\Repositories\Contracts\SettingsRepositoryInterface;
use Illuminate\Support\Facades\Cache;

class SettingsRepository extends BaseRepository implements SettingsRepositoryInterface
{
    protected string $cacheKey = 'cms_settings';
    protected int $cacheTtl = 3600;

    protected function getModel(): Model
    {
        return new Setting();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $settings = $this->getCachedSettings();
        return $settings->get($key, $default);
    }

    public function set(string $key, mixed $value): bool
    {
        try {
            $this->model->updateOrCreate(
                ['key' => $key],
                ['value' => $this->serializeValue($value)]
            );

            $this->clearCache();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function has(string $key): bool
    {
        return $this->getCachedSettings()->has($key);
    }

    public function delete(string $key): bool
    {
        try {
            $deleted = $this->model->where('key', $key)->delete() > 0;
            if ($deleted) {
                $this->clearCache();
            }
            return $deleted;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function getGroup(string $group): Collection
    {
        return $this->getCachedSettings()
            ->filter(fn($value, $key) => str_starts_with($key, $group . '.'))
            ->mapWithKeys(fn($value, $key) => [
                str_replace($group . '.', '', $key) => $value
            ]);
    }

    public function setGroup(string $group, array $settings): bool
    {
        try {
            \DB::beginTransaction();

            foreach ($settings as $key => $value) {
                $this->set($group . '.' . $key, $value);
            }

            \DB::commit();
            $this->clearCache();
            return true;
        } catch (\Exception $e) {
            \DB::rollBack();
            return false;
        }
    }

    public function deleteGroup(string $group): bool
    {
        try {
            $deleted = $this->model->where('key', 'like', $group . '.%')->delete() > 0;
            if ($deleted) {
                $this->clearCache();
            }
            return $deleted;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function getMultiple(array $keys): array
    {
        return $this->getCachedSettings()
            ->only($keys)
            ->all();
    }

    public function setMultiple(array $settings): bool
    {
        try {
            \DB::beginTransaction();

            foreach ($settings as $key => $value) {
                $this->set($key, $value);
            }

            \DB::commit();
            $this->clearCache();
            return true;
        } catch (\Exception $e) {
            \DB::rollBack();
            return false;
        }
    }

    public function getAll(): Collection
    {
        return $this->getCachedSettings();
    }

    public function flush(): bool
    {
        try {
            $this->model->truncate();
            $this->clearCache();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    protected function getCachedSettings(): Collection
    {
        return Cache::remember($this->cacheKey, $this->cacheTtl, function () {
            return $this->model->all()
                ->mapWithKeys(fn($setting) => [
                    $setting->key => $this->unserializeValue($setting->value)
                ]);
        });
    }

    protected function clearCache(): void
    {
        Cache::forget($this->cacheKey);
    }

    protected function serializeValue(mixed $value): string
    {
        return serialize($value);
    }

    protected function unserializeValue(string $value): mixed
    {
        return unserialize($value);
    }
}
