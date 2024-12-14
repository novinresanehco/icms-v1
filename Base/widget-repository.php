<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Widget;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Repositories\Interfaces\WidgetRepositoryInterface;

class WidgetRepository implements WidgetRepositoryInterface
{
    private const CACHE_PREFIX = 'widget:';
    private const CACHE_TTL = 3600;

    public function __construct(
        private readonly Widget $model
    ) {}

    public function findById(int $id): ?Widget
    {
        return Cache::remember(
            self::CACHE_PREFIX . $id,
            self::CACHE_TTL,
            fn () => $this->model->find($id)
        );
    }

    public function findByKey(string $key): ?Widget
    {
        return Cache::remember(
            self::CACHE_PREFIX . "key:{$key}",
            self::CACHE_TTL,
            fn () => $this->model->where('key', $key)->first()
        );
    }

    public function create(array $data): Widget
    {
        $widget = $this->model->create([
            'name' => $data['name'],
            'key' => $data['key'],
            'description' => $data['description'] ?? null,
            'type' => $data['type'],
            'content' => $data['content'] ?? [],
            'settings' => $data['settings'] ?? [],
            'position' => $data['position'] ?? null,
            'order' => $data['order'] ?? 0,
            'is_active' => $data['is_active'] ?? true,
            'cache_ttl' => $data['cache_ttl'] ?? 3600,
            'author_id' => auth()->id()
        ]);

        $this->clearCache();

        return $widget;
    }

    public function update(int $id, array $data): bool
    {
        $widget = $this->findById($id);
        
        if (!$widget) {
            return false;
        }

        $updated = $widget->update([
            'name' => $data['name'] ?? $widget->name,
            'key' => $data['key'] ?? $widget->key,
            'description' => $data['description'] ?? $widget->description,
            'content' => $data['content'] ?? $widget->content,
            'settings' => $data['settings'] ?? $widget->settings,
            'position' => $data['position'] ?? $widget->position,
            'order' => $data['order'] ?? $widget->order,
            'is_active' => $data['is_active'] ?? $widget->is_active,
            'cache_ttl' => $data['cache_ttl'] ?? $widget->cache_ttl
        ]);

        if ($updated) {
            $this->clearCache();
        }

        return $updated;
    }

    public function delete(int $id): bool
    {
        $widget = $this->findById($id);
        
        if (!$widget || $widget->is_system) {
            return false;
        }

        $deleted = $widget->delete();

        if ($deleted) {
            $this->clearCache();
        }

        return $deleted;
    }

    public function getAll(): Collection
    {
        return Cache::remember(
            self::CACHE_PREFIX . 'all',
            self::CACHE_TTL,
            fn () => $this->model->orderBy('position')->orderBy('order')->get()
        );
    }

    public function getByPosition(string $position): Collection
    {
        return Cache::remember(
            self::CACHE_PREFIX . "position:{$position}",
            self::CACHE_TTL,
            fn () => $this->model->active()
                ->where('position', $position)
                ->orderBy('order')
                ->get()
        );
    }

    public function getByType(string $type): Collection
    {
        return Cache::remember(
            self::CACHE_PREFIX . "type:{$type}",
            self::CACHE_TTL,
            fn () => $this->model->where('type', $type)->get()
        );
    }

    public function updateOrder(array $positions): bool
    {
        return DB::transaction(function () use ($positions) {
            foreach ($positions as $position => $items) {
                foreach ($items as $order => $id) {
                    $this->model->where('id', $id)->update([
                        'position' => $position,
                        'order' => $order
                    ]);
                }
            }

            $this->clearCache();

            return true;
        });
    }

    public function getPositions(): Collection
    {
        return Cache::remember(
            self::CACHE_PREFIX . 'positions',
            self::CACHE_TTL,
            fn () => $this->model->select('position')
                ->distinct()
                ->whereNotNull('position')
                ->orderBy('position')
                ->pluck('position')
        );
    }

    protected function clearCache(): void
    {
        $keys = ['all', 'positions'];
        foreach ($keys as $key) {
            Cache::forget(self::CACHE_PREFIX . $key);
        }
    }
}