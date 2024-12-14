<?php

namespace App\Core\Repositories;

use App\Core\Models\Setting;
use App\Core\Repositories\Contracts\SettingRepositoryInterface;
use Illuminate\Support\Collection;

class SettingRepository implements SettingRepositoryInterface
{
    public function __construct(
        private Setting $model
    ) {}

    public function get(string $key, $default = null)
    {
        $setting = $this->model->where('key', $key)->first();
        
        if (!$setting) {
            return $default;
        }

        return $this->castValue($setting->value, $setting->type);
    }

    public function set(string $key, $value): bool
    {
        $type = $this->getValueType($value);
        $value = $this->prepareValue($value);

        return $this->model->updateOrCreate(
            ['key' => $key],
            [
                'value' => $value,
                'type' => $type
            ]
        )->exists;
    }

    public function has(string $key): bool
    {
        return $this->model->where('key', $key)->exists();
    }

    public function remove(string $key): bool
    {
        return $this->model->where('key', $key)->delete() > 0;
    }

    public function getAllByGroup(string $group): Collection
    {
        return $this->model
            ->where('group', $group)
            ->get()
            ->mapWithKeys(function ($setting) {
                return [
                    $setting->key => $this->castValue($setting->value, $setting->type)
                ];
            });
    }

    public function getAll(): Collection
    {
        return $this->model
            ->get()
            ->mapWithKeys(function ($setting) {
                return [
                    $setting->key => $this->castValue($setting->value, $setting->type)
                ];
            });
    }

    public function setMany(array $settings): bool
    {
        try {
            foreach ($settings as $key => $value) {
                $this->set($key, $value);
            }
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function removeMany(array $keys): bool
    {
        return $this->model->whereIn('key', $keys)->delete() > 0;
    }

    public function removeByGroup(string $group): bool
    {
        return $this->model->where('group', $group)->delete() > 0;
    }

    private function castValue($value, string $type)
    {
        return match($type) {
            'boolean' => (bool) $value,
            'integer' => (int) $value,
            'float' => (float) $value,
            'array', 'object' => json_decode($value, true),
            default => $value
        };
    }

    private function getValueType($value): string
    {
        return match(true) {
            is_bool($value) => 'boolean',
            is_int($value) => 'integer',
            is_float($value) => 'float',
            is_array($value) => 'array',
            is_object($value) => 'object',
            default => 'string'
        };
    }

    private function prepareValue($value): string
    {
        if (is_array($value) || is_object($value)) {
            return json_encode($value);
        }
        return (string) $value;
    }
}
