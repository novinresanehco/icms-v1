<?php

namespace App\Core\Settings\Repository;

use App\Core\Settings\Models\Setting;
use App\Core\Settings\DTO\SettingData;
use App\Core\Settings\Events\SettingUpdated;
use App\Core\Settings\Events\SettingsGroupCreated;
use App\Core\Settings\Events\SettingsImported;
use App\Core\Settings\Exceptions\SchemaValidationException;
use App\Core\Settings\Exceptions\SettingNotFoundException;
use App\Core\Shared\Repository\BaseRepository;
use App\Core\Shared\Cache\CacheManagerInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;

class SettingsRepository extends BaseRepository implements SettingsRepositoryInterface
{
    protected const CACHE_KEY = 'settings';
    protected const CACHE_TTL = 3600; // 1 hour

    public function __construct(CacheManagerInterface $cache)
    {
        parent::__construct($cache);
        $this->setCacheKey(self::CACHE_KEY);
        $this->setCacheTtl(self::CACHE_TTL);
    }

    protected function getModelClass(): string
    {
        return Setting::class;
    }

    public function getByKey(string $key): ?Setting
    {
        return $this->cache->remember(
            $this->getCacheKey("key:{$key}"),
            fn() => $this->model->where('key', $key)->first()
        );
    }

    public function getByGroup(string $group): Collection
    {
        return $this->cache->remember(
            $this->getCacheKey("group:{$group}"),
            fn() => $this->model->where('group', $group)
                               ->orderBy('key')
                               ->get()
        );
    }

    public function getAllAsArray(?string $group = null): array
    {
        $cacheKey = $group ? "array:group:{$group}" : 'array:all';

        return $this->cache->remember(
            $this->getCacheKey($cacheKey),
            function() use ($group) {
                $query = $this->model->newQuery();
                
                if ($group) {
                    $query->where('group', $group);
                }

                return $query->pluck('value', 'key')->toArray();
            }
        );
    }

    public function setMany(array $settings, ?string $group = null): bool
    {
        DB::beginTransaction();
        try {
            foreach ($settings as $key => $value) {
                $setting = $this->model->firstOrNew(['key' => $key]);
                $oldValue = $setting->value;

                $setting->fill([
                    'value' => $value,
                    'group' => $group ?? $setting->group,
                    'updated_at' => now()
                ]);

                if ($setting->isDirty('value')) {
                    $setting->save();
                    Event::dispatch(new SettingUpdated($setting, $oldValue));
                }
            }

            // Clear cache
            $this->clearCache();

            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function deleteByGroup(string $group): bool
    {
        DB::beginTransaction();
        try {
            $deleted = $this->model->where('group', $group)->delete();

            // Clear cache
            $this->clearCache();

            DB::commit();
            return $deleted > 0;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function getSchema(string $group): array
    {
        return $this->cache->remember(
            $this->getCacheKey("schema:{$group}"),
            fn() => $this->model->where('group', $group)
                               ->where('is_schema', true)
                               ->value('schema') ?? []
        );
    }

    public function validateAgainstSchema(array $settings, string $group): array
    {
        $schema = $this->getSchema($group);
        if (empty($schema)) {
            return [];
        }

        $validator = Validator::make($settings, $schema['rules'] ?? []);
        
        if ($validator->fails()) {
            return $validator->errors()->toArray();
        }

        return [];
    }

    public function registerGroup(string $group, array $schema, array $defaultValues = []): bool
    {
        DB::beginTransaction();
        try {
            // Store schema
            $this->model->create([
                'key' => "{$group}.schema",
                'value' => null,
                'group' => $group,
                'is_schema' => true,
                'schema' => $schema
            ]);

            // Store default values
            foreach ($defaultValues as $key => $value) {
                $this->model->create([
                    'key' => $key,
                    'value' => $value,
                    'group' => $group
                ]);
            }

            // Clear cache
            $this->clearCache();

            // Dispatch event
            Event::dispatch(new SettingsGroupCreated($group, $schema));

            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function getModifiedSince(string $timestamp): Collection
    {
        return $this->model->where('updated_at', '>', $timestamp)
                          ->orderBy('updated_at')
                          ->get();
    }

    public function exportToFile(string $path, ?string $group = null): bool
    {
        try {
            $query = $this->model->where('is_schema', false);
            
            if ($group) {
                $query->where('group', $group);
            }

            $settings = $query->get()->map(function ($setting) {
                return [
                    'key' => $setting->key,
                    'value' => $setting->value,
                    'group' => $setting->group
                ];
            })->toArray();

            File::put($path, json_encode($settings, JSON_PRETTY_PRINT));
            return true;
        } catch (\Exception $e) {
            throw new \RuntimeException("Failed to export settings: {$e->getMessage()}");
        }
    }

    public function importFromFile(string $path, bool $overwrite = false): array
    {
        if (!File::exists($path)) {
            throw new \InvalidArgumentException("Settings file not found: {$path}");
        }

        DB::beginTransaction();
        try {
            $settings = json_decode(File::get($path), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \InvalidArgumentException("Invalid JSON in settings file");
            }

            $stats = ['imported' => 0, 'updated' => 0, 'skipped' => 0];

            foreach ($settings as $setting) {
                $existing = $this->model->where('key', $setting['key'])->first();

                if ($existing && !$overwrite) {
                    $stats['skipped']++;
                    continue;
                }

                if ($existing) {
                    $existing->update([
                        'value' => $setting['value'],
                        'group' => $setting['group']
                    ]);
                    $stats['updated']++;
                } else {
                    $this->model->create([
                        'key' => $setting['key'],
                        'value' => $setting['value'],
                        'group' => $setting['group']
                    ]);
                    $stats['imported']++;
                }
            }

            // Clear cache
            $this->clearCache();

            // Dispatch event
            Event::dispatch(new SettingsImported($stats));

            DB::commit();
            return $stats;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
