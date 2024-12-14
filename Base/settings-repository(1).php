<?php

namespace App\Repositories;

use App\Models\Setting;
use App\Repositories\Contracts\SettingsRepositoryInterface;
use Illuminate\Support\Collection;

class SettingsRepository extends BaseRepository implements SettingsRepositoryInterface
{
    protected array $searchableFields = ['key', 'description'];
    protected array $filterableFields = ['group', 'type'];

    public function __construct(Setting $model)
    {
        parent::__construct($model);
    }

    public function get(string $key, $default = null)
    {
        try {
            return Cache::remember(
                $this->getCacheKey("key.{$key}"),
                $this->cacheTTL,
                function () use ($key, $default) {
                    $setting = $this->model->where('key', $key)->first();
                    return $setting ? $this->castValue($setting) : $default;
                }
            );
        } catch (\Exception $e) {
            Log::error('Failed to get setting: ' . $e->getMessage());
            return $default;
        }
    }

    public function set(string $key, $value, ?string $group = null): bool
    {
        try {
            DB::beginTransaction();

            $setting = $this->model->firstOrNew(['key' => $key]);
            $setting->fill([
                'value' => $value,
                'group' => $group,
                'type' => $this->determineType($value)
            ]);
            $setting->save();

            DB::commit();
            $this->clearModelCache();

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to set setting: ' . $e->getMessage());
            return false;
        }
    }

    public function getByGroup(string $group): Collection
    {
        try {
            return Cache::remember(
                $this->getCacheKey("group.{$group}"),
                $this->cacheTTL,
                fn() => $this->model->where('group', $group)
                    ->get()
                    ->mapWithKeys(function ($setting) {
                        return [$setting->key => $this->castValue($setting)];
                    })
            );
        } catch (\Exception $e) {
            Log::error('Failed to get settings by group: ' . $e->getMessage());
            return new Collection();
        }
    }

    protected function castValue(Setting $setting)
    {
        return match ($setting->type) {
            'boolean' => (bool) $setting->value,
            'integer' => (int) $setting->value,
            'float' => (float) $setting->value,
            'array', 'json' => json_decode($setting->value, true),
            default => $setting->value,
        };
    }

    protected function determineType($value): string
    {
        return match (true) {
            is_bool($value) => 'boolean',
            is_int($value) => 'integer',
            is_float($value) => 'float',
            is_array($value) => 'array',
            default => 'string',
        };
    }
}
