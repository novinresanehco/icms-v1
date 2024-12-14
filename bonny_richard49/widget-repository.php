<?php

namespace App\Core\Widget\Repository;

use App\Core\Widget\Models\Widget;
use App\Core\Widget\DTO\WidgetData;
use App\Core\Widget\Events\WidgetCreated;
use App\Core\Widget\Events\WidgetUpdated;
use App\Core\Widget\Events\WidgetDeleted;
use App\Core\Widget\Services\WidgetRenderer;
use App\Core\Widget\Exceptions\WidgetException;
use App\Core\Shared\Repository\BaseRepository;
use App\Core\Shared\Cache\CacheManagerInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Cache;

class WidgetRepository extends BaseRepository implements WidgetRepositoryInterface
{
    protected const CACHE_KEY = 'widgets';
    protected const CACHE_TTL = 3600; // 1 hour

    protected WidgetRenderer $renderer;

    public function __construct(
        CacheManagerInterface $cache,
        WidgetRenderer $renderer
    ) {
        parent::__construct($cache);
        $this->renderer = $renderer;
        $this->setCacheKey(self::CACHE_KEY);
        $this->setCacheTtl(self::CACHE_TTL);
    }

    protected function getModelClass(): string
    {
        return Widget::class;
    }

    public function getByArea(string $area): Collection
    {
        return $this->cache->remember(
            $this->getCacheKey("area:{$area}"),
            fn() => $this->model->where('area', $area)
                               ->where('is_active', true)
                               ->orderBy('order')
                               ->get()
        );
    }

    public function findByIdentifier(string $identifier): ?Widget
    {
        return $this->cache->remember(
            $this->getCacheKey("identifier:{$identifier}"),
            fn() => $this->model->where('identifier', $identifier)->first()
        );
    }

    public function createWidget(WidgetData $data): Widget
    {
        DB::beginTransaction();
        try {
            // Set order if not provided
            if (!isset($data->order)) {
                $data->order = $this->getNextOrder($data->area);
            }

            // Create widget
            $widget = $this->model->create([
                'name' => $data->name,
                'identifier' => $data->identifier,
                'type' => $data->type,
                'area' => $data->area,
                'settings' => $data->settings,
                'order' => $data->order,
                'is_active' => $data->isActive,
                'cache_ttl' => $data->cacheTtl,
                'visibility_rules' => $data->visibilityRules,
                'permissions' => $data->permissions,
                'metadata' => $data->metadata
            ]);

            // Clear cache
            $this->clearCache();

            // Dispatch event
            Event::dispatch(new WidgetCreated($widget));

            DB::commit();
            return $widget->fresh();
        } catch (\Exception $e) {
            DB::rollBack();
            throw new WidgetException("Failed to create widget: {$e->getMessage()}", 0, $e);
        }
    }

    public function updateWidget(int $id, WidgetData $data): Widget
    {
        DB::beginTransaction();
        try {
            $widget = $this->findOrFail($id);

            // Update widget
            $widget->update([
                'name' => $data->name,
                'settings' => array_merge($widget->settings ?? [], $data->settings ?? []),
                'is_active' => $data->isActive,
                'cache_ttl' => $data->cacheTtl,
                'visibility_rules' => $data->visibilityRules,
                'permissions' => $data->permissions,
                'metadata' => array_merge($widget->metadata ?? [], $data->metadata ?? [])
            ]);

            // Clear cache
            $this->clearCache();
            $this->clearWidgetCache($widget);

            // Dispatch event
            Event::dispatch(new WidgetUpdated($widget));

            DB::commit();
            return $widget->fresh();
        } catch (\Exception $e) {
            DB::rollBack();
            throw new WidgetException("Failed to update widget: {$e->getMessage()}", 0, $e);
        }
    }

    public function updateOrder(string $area, array $order): bool
    {
        DB::beginTransaction();
        try {
            foreach ($order as $position => $widgetId) {
                $this->model->where('id', $widgetId)
                           ->where('area', $area)
                           ->update(['order' => $position + 1]);
            }

            // Clear cache
            $this->clearCache();

            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new WidgetException("Failed to update widget order: {$e->getMessage()}", 0, $e);
        }
    }

    public function getSettings(int $id): array
    {
        $widget = $this->findOrFail($id);
        return $widget->settings ?? [];
    }

    public function getByPage(int $pageId): Collection
    {
        return $this->cache->remember(
            $this->getCacheKey("page:{$pageId}"),
            fn() => $this->model->whereJsonContains('visibility_rules->pages', $pageId)
                               ->where('is_active', true)
                               ->orderBy('area')
                               ->orderBy('order')
                               ->get()
        );
    }

    public function duplicate(int $id, array $overrides = []): Widget
    {
        DB::beginTransaction();
        try {
            $widget = $this->findOrFail($id);

            $data = new WidgetData(array_merge([
                'name' => $widget->name . ' (Copy)',
                'identifier' => $widget->identifier . '-copy',
                'type' => $widget->type,
                'area' => $widget->area,
                'settings' => $widget->settings,
                'order' => $this->getNextOrder($widget->area),
                'is_active' => false,
                'cache_ttl' => $widget->cache_ttl,
                'visibility_rules' => $widget->visibility_rules,
                'permissions' => $widget->permissions,
                'metadata' => $widget->metadata
            ], $overrides));

            return $this->createWidget($data);
        } catch (\Exception $e) {
            DB::rollBack();
            throw new WidgetException("Failed to duplicate widget: {$e->getMessage()}", 0, $e);
        }
    }

    public function getUsageStats(int $id): array
    {
        return $this->cache->remember(
            $this->getCacheKey("stats:{$id}"),
            function() use ($id) {
                $widget = $this->findOrFail($id);

                return [
                    'page_count' => count($widget->visibility_rules['pages'] ?? []),
                    'cache_hits' => Cache::get("widget.{$id}.cache_hits", 0),
                    'last_rendered' => Cache::get("widget.{$id}.last_rendered"),
                    'average_render_time' => Cache::get("widget.{$id}.avg_render_time"),
                ];
            }
        );
    }

    public function cacheOutput(int $id, string $output, int $ttl = 3600): bool
    {
        try {
            Cache::put("widget.{$id}.output", $output, $ttl);
            Cache::increment("widget.{$id}.cache_hits");
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function getCachedOutput(int $id): ?string
    {
        return Cache::get("widget.{$id}.output");
    }

    public function getGlobalWidgets(): Collection
    {
        return $this->cache->remember(
            $this->getCacheKey('global'),
            fn() => $this->model->where('visibility_rules->global', true)
                               ->where('is_active', true)
                               ->orderBy('area')
                               ->orderBy('order')
                               ->get()
        );
    }

    public function importWidget(array $config): Widget
    {
        DB::beginTransaction();
        try {
            $data = new WidgetData(array_merge($config, [
                'order' => $this->getNextOrder($config['area']),
                'is_active' => false
            ]));

            return $this->createWidget($data);
        } catch (\Exception $e) {
            DB::rollBack();
            throw new WidgetException("Failed to import widget: {$e->getMessage()}", 0, $e);
        }
    }

    protected function getNextOrder(string $area): int
    {
        return $this->model->where('area', $area)->max('order') + 1;
    }

    protected function clearWidgetCache(Widget $widget): void
    {
        Cache::forget("widget.{$widget->id}.output");
    }
}
