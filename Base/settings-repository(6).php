<?php

namespace App\Repositories;

use App\Models\Setting;
use App\Core\Repositories\AbstractRepository;
use Illuminate\Support\Collection;

class SettingsRepository extends AbstractRepository
{
    protected array $searchable = ['key', 'group'];

    public function get(string $key, $default = null)
    {
        return $this->executeQuery(function() use ($key, $default) {
            $setting = $this->model->where('key', $key)->first();
            return $setting ? $this->castValue($setting) : $default;
        });
    }

    public function set(string $key, $value, string $group = 'general'): void
    {
        $this->beginTransaction();
        try {
            $this->model->updateOrCreate(
                ['key' => $key],
                [
                    'value' => $this->serializeValue($value),
                    'type' => $this->getValueType($value),
                    'group' => $group
                ]
            );
            $this->commit();
        } catch (\Exception $e) {
            $this->rollback();
            throw $e;
        }
    }

    public function getGroup(string $group): Collection
    {
        return $this->executeQuery(function() use ($group) {
            return $this->model->where('group', $group)
                ->get()
                ->mapWithKeys(function($setting) {
                    return [$setting->key => $this->castValue($setting)];
                });
        });
    }

    public function bulk(array $settings): void
    {
        $this->beginTransaction();
        try {
            foreach ($settings as $key => $value) {
                $this->set($key, $value);
            }
            $this->commit();
        } catch (\Exception $e) {
            $this->rollback();
            throw $e;
        }
    }

    protected function castValue(Setting $setting)
    {
        return match($setting->type) {
            'boolean' => (bool)$setting->value,
            'integer' => (int)$setting->value,
            'float' => (float)$setting->value,
            'array' => json_decode($setting->value, true),
            'object' => json_decode($setting->value),
            default => $setting->value
        };
    }

    protected function serializeValue($value): string
    {
        if (is_array($value) || is_object($value)) {
            return json_encode($value);
        }
        return (string)$value;
    }

    protected function getValueType($value): string
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
}
