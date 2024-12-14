<?php

namespace App\Core\Repositories\Contracts;

interface SettingsRepositoryInterface
{
    public function get(string $key, $default = null);
    public function set(string $key, $value): void;
    public function forget(string $key): void;
    public function has(string $key): bool;
    public function all(): array;
    public function getByGroup(string $group): array;
}

namespace App\Core\Repositories;

use App\Core\Repositories\Contracts\SettingsRepositoryInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SettingsRepository implements SettingsRepositoryInterface
{
    protected string $table = 'settings';
    protected string $cacheKey = 'cms.settings';
    protected ?array $settings = null;

    public function get(string $key, $default = null)
    {
        $settings = $this->loadSettings();
        return $settings[$key] ?? $default;
    }

    public function set(string $key, $value): void
    {
        DB::table($this->table)->updateOrInsert(
            ['key' => $key],
            [
                'value' => is_array($value) ? json_encode($value) : $value,
                'updated_at' => now()
            ]
        );
        
        $this->clearCache();
    }

    public function forget(string $key): void
    {
        DB::table($this->table)->where('key', $key)->delete();
        $this->clearCache();
    }

    public function has(string $key): bool
    {
        $settings = $this->loadSettings();
        return isset($settings[$key]);
    }

    public function all(): array
    {
        return $this->loadSettings();
    }

    public function getByGroup(string $group): array
    {
        $settings = $this->loadSettings();
        return array_filter($settings, function ($key) use ($group) {
            return strpos($key, $group . '.') === 0;
        }, ARRAY_FILTER_USE_KEY);
    }

    protected function loadSettings(): array
    {
        if ($this->settings !== null) {
            return $this->settings;
        }

        $this->settings = Cache::remember($this->cacheKey, now()->addDay(), function () {
            return DB::table($this->table)
                ->pluck('value', 'key')
                ->map(function ($value) {
                    $decoded = json_decode($value, true);
                    return $decoded === null ? $value : $decoded;
                })
                ->toArray();
        });

        return $this->settings;
    }

    protected function clearCache(): void
    {
        Cache::forget($this->cacheKey);
        $this->settings = null;
    }
}
