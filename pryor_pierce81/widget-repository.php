<?php

namespace App\Core\Repository;

use App\Models\Widget;
use App\Core\Events\WidgetEvents;
use App\Core\Exceptions\WidgetRepositoryException;

class WidgetRepository extends BaseRepository
{
    protected function getModelClass(): string
    {
        return Widget::class;
    }

    /**
     * Get active widgets for a specific area
     */
    public function getActiveWidgetsForArea(string $area): Collection
    {
        return Cache::tags($this->getCacheTags())->remember(
            $this->getCacheKey('active', $area),
            $this->cacheTime,
            fn() => $this->model->where('area', $area)
                               ->where('status', 'active')
                               ->orderBy('order')
                               ->get()
        );
    }

    /**
     * Update widget positions
     */
    public function updatePositions(array $positions): void
    {
        try {
            DB::transaction(function() use ($positions) {
                foreach ($positions as $position) {
                    $this->model->where('id', $position['id'])
                               ->update([
                                   'area' => $position['area'],
                                   'order' => $position['order']
                               ]);
                }
            });

            $this->clearCache();
            event(new WidgetEvents\WidgetPositionsUpdated($positions));
        } catch (\Exception $e) {
            throw new WidgetRepositoryException(
                "Failed to update widget positions: {$e->getMessage()}"
            );
        }
    }

    /**
     * Get widget configuration
     */
    public function getConfig(int $id): array
    {
        return Cache::tags($this->getCacheTags())->remember(
            $this->getCacheKey('config', $id),
            $this->cacheTime,
            function() use ($id) {
                $widget = $this->find($id);
                return $widget ? $widget->configuration : [];
            }
        );
    }

    /**
     * Update widget configuration
     */
    public function updateConfig(int $id, array $config): Widget
    {
        try {
            $widget = $this->find($id);
            if (!$widget) {
                throw new WidgetRepositoryException("Widget not found with ID: {$id}");
            }

            $widget->update(['configuration' => $config]);
            $this->clearCache();

            event(new WidgetEvents\WidgetConfigUpdated($widget));
            return $widget;
        } catch (\Exception $e) {
            throw new WidgetRepositoryException(
                "Failed to update widget configuration: {$e->getMessage()}"
            );
        }
    }
}
