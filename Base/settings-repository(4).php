<?php

namespace App\Core\Repositories;

use App\Core\Models\Setting;
use App\Core\Events\{SettingUpdated, SettingsGroupUpdated};
use App\Core\Exceptions\SettingException;
use Illuminate\Database\Eloquent\{Model, Collection};
use Illuminate\Support\Facades\{DB, Event, Cache};

class SettingsRepository extends Repository
{
    protected string $cachePrefix = 'settings:';
    protected int $cacheDuration = 86400; // 24 hours

    public function get(string $key, mixed $default = null): mixed
    {
        return Cache::remember(
            $this->getCacheKey($key),
            $this->cacheDuration,
            fn() => $this->query()
                ->where('key', $key)
                ->value('value') ?? $default
        );
    }

    public function set(string $key, mixed $value, ?string $group = null): bool
    {
        return DB::transaction(function() use ($key, $value, $group) {
            $setting = $this->query()->where('key', $key)->first();

            if ($setting) {
                $updated = $this->update($setting, [
                    'value' => $value,
                    'group' => $group
                ]);
            } else {
                $setting = $this->create([
                    'key' => $key,
                    'value' => $value,
                    'group' => $group
                ]);
                $updated = (bool)$setting;
            }

            if ($updated) {
                $this->clearCache($key);
                Event::dispatch(new SettingUpdated($setting));
            }

            return $updated;
        });
    }

    public function getGroup(string $group): Collection
    {
        return Cache::remember(
            $this->getCacheKey("group:{$group}"),
            $this->cacheDuration,
            fn() => $this->query()
                ->where('group', $group)
                ->get()
        );
    }

    public function setGroup(string $group, array $values): bool
    {
        return DB::transaction(function() use ($group, $values) {
            $updated = true;

            foreach ($values as $key => $value) {
                if (!$this->set($key, $value, $group)) {
                    $updated = false;
                }
            }

            if ($updated) {
                $this->clearGroupCache($group);
                Event::dispatch(new SettingsGroupUpdated($group, $values));
            }

            return $updated;
        });
    }

    public function delete(string $key): bool
    {
        $setting = $this->query()->where('key', $key)->first();
        
        if (!$setting) {
            return false;
        }

        if (parent::delete($setting)) {
            $this->clearCache($key);
            return true;
        }

        return false;
    }

    public function deleteGroup(string $group): bool
    {
        return DB::transaction(function() use ($group) {
            $deleted = $this->query()
                ->where('group', $group)
                ->delete();

            if ($deleted) {
                $this->clearGroupCache($group);
            }

            return (bool)$deleted;
        });
    }

    protected function getCacheKey(string $key): string
    {
        return $this->cachePrefix . $key;
    }

    protected function clearCache(string $key): void
    {
        Cache::forget($this->getCacheKey($key));
    }

    protected function clearGroupCache(string $group): void
    {
        Cache::forget($this->getCacheKey("group:{$group}"));
    }
}

class SettingsSchemaRepository extends Repository
{
    public function getSchema(string $group): ?Model
    {
        return $this->remember(fn() =>
            $this->query()
                ->where('group', $group)
                ->first()
        );
    }

    public function validateValue(string $key, mixed $value): bool
    {
        $schema = $this->query()
            ->where('key', $key)
            ->first();

        if (!$schema) {
            return true;
        }

        return $this->validateAgainstSchema($value, $schema->validation);
    }

    protected function validateAgainstSchema(mixed $value, array $schema): bool
    {
        $validator = validator(
            ['value' => $value],
            ['value' => $schema['rules'] ?? []]
        );

        return !$validator->fails();
    }
}

class SettingsHistoryRepository extends Repository
{
    public function logChange(string $key, mixed $oldValue, mixed $newValue): Model
    {
        return $this->create([
            'key' => $key,
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'changed_by' => auth()->id()
        ]);
    }

    public function getHistory(string $key): Collection
    {
        return $this->query()
            ->where('key', $key)
            ->with('changedBy')
            ->orderByDesc('created_at')
            ->get();
    }

    public function getRecentChanges(int $limit = 50): Collection
    {
        return $this->query()
            ->with(['changedBy'])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }
}
