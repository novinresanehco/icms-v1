<?php

namespace App\Core\Repositories;

use App\Core\Repositories\Contracts\SettingsRepositoryInterface;
use App\Models\Setting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class SettingsRepository extends BaseRepository implements SettingsRepositoryInterface
{
    public function __construct(Setting $model)
    {
        parent::__construct($model);
    }

    public function getAllSettings(): Collection
    {
        return Cache::tags(['settings'])->remember(
            'settings:all',
            now()->addDay(),
            fn () => $this->model
                ->orderBy('group')
                ->get()
                ->groupBy('group')
        );
    }

    public function getValue(string $key, $default = null)
    {
        return Cache::tags(['settings'])->remember(
            "settings:value:{$key}",
            now()->addDay(),
            function () use ($key, $default) {
                $setting = $this->model->where('key', $key)->first();
                return $setting ? $this->castValue($setting->value, $setting->type) : $default;
            }
        );
    }

    public function setValues(array $settings): bool
    {
        $success = true;

        foreach ($settings as $key => $value) {
            $setting = $this->model->where('key', $key)->first();
            
            if ($setting) {
                $success = $success && $this->update($setting->id, ['value' => $value]);
            } else {
                $success = $success && $this->create([
                    'key' => $key,
                    'value' => $value,
                    'type' => $this->guessValueType($value)
                ]) instanceof Setting;
            }
        }

        if ($success) {
            Cache::tags(['settings'])->flush();
        }

        return $success;
    }

    public function getGroup(string $group): Collection
    {
        return Cache::tags(['settings', "group:{$group}"])->remember(
            "settings:group:{$group}",
            now()->addDay(),
            fn () => $this->model
                ->where('group', $group)
                ->get()
                ->mapWithKeys(function ($setting) {
                    return [$setting->key => $this->castValue($setting->value, $setting->type)];
                })
        );
    }

    protected function castValue($value, string $type)
    {
        return match($type) {
            'boolean' => (bool) $value,
            'integer' => (int) $value,
            'float' => (float) $value,
            'json' => json_decode($value, true),
            'array' => explode(',', $value),
            default => $value,
        };
    }

    protected function guessValueType($value): string
    {
        return match(true) {
            is_bool($value) => 'boolean',
            is_int($value) => 'integer',
            is_float($value) => 'float',
            is_array($value) => 'json',
            default => 'string',
        };
    }
}
