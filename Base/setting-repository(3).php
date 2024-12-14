<?php

namespace App\Repositories;

use App\Models\Setting;
use App\Repositories\Contracts\SettingRepositoryInterface;
use Illuminate\Support\Collection;

class SettingRepository extends BaseRepository implements SettingRepositoryInterface
{
    protected array $searchableFields = ['key', 'value'];
    protected array $filterableFields = ['group'];

    public function __construct(Setting $model) 
    {
        parent::__construct($model);
    }

    public function get(string $key, $default = null)
    {
        return Cache::remember(
            $this->getCacheKey("key.{$key}"),
            $this->cacheTTL,
            fn() => $this->model->where('key', $key)->value('value') ?? $default
        );
    }

    public function getGroup(string $group): Collection
    {
        return Cache::remember(
            $this->getCacheKey("group.{$group}"),
            $this->cacheTTL,
            fn() => $this->model->where('group', $group)->pluck('value', 'key')
        );
    }

    public function set(string $key, $value, string $group = 'general'): void
    {
        $this->model->updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'group' => $group]
        );
        
        $this->clearModelCache();
    }

    public function setMany(array $settings, string $group = 'general'): void
    {
        try {
            DB::beginTransaction();
            
            foreach ($settings as $key => $value) {
                $this->set($key, $value, $group);
            }
            
            DB::commit();
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw new RepositoryException("Failed to set multiple settings: {$e->getMessage()}");
        }
    }
}
