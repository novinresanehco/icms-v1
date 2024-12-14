<?php

namespace App\Core\Settings\Repositories;

use App\Core\Settings\Models\Setting;
use Illuminate\Support\Collection;

class SettingRepository
{
    public function getValue(string $key, $default = null)
    {
        $setting = Setting::where('key', $key)->first();
        return $setting ? $setting->getValue() : $default;
    }

    public function set(string $key, $value): Setting
    {
        return Setting::updateOrCreate(
            ['key' => $key],
            [
                'value' => $value,
                'type' => $this->determineType($value),
                'group' => $this->determineGroup($key)
            ]
        );
    }

    public function delete(string $key): bool
    {
        return Setting::where('key', $key)->delete() > 0;
    }

    public function getByGroup(string $group): Collection
    {
        return Setting::inGroup($group)
                     ->get()
                     ->mapWithKeys(fn($setting) => [
                         $setting->key => $setting->getValue()
                     ]);
    }

    public function all(): Collection
    {
        return Setting::all()->mapWithKeys(fn($setting) => [
            $setting->key => $setting->getValue()
        ]);
    }

    protected function determineType($value): string
    {
        return match(true) {
            is_bool($value) => 'boolean',
            is_int($value) => 'integer',
            is_float($value) => 'float',
            is_array($value) => 'array',
            default => 'string'
        };
    }

    protected function determineGroup(string $key): string
    {
        return explode('.', $key)[0] ?? 'general';
    }
}
