<?php

namespace App\Core\Repositories;

use App\Models\Widget;
use App\Core\Services\Cache\CacheService;
use Illuminate\Support\Collection;

class WidgetRepository extends AdvancedRepository
{
    protected $model = Widget::class;
    protected $cache;

    public function __construct(CacheService $cache)
    {
        parent::__construct();
        $this->cache = $cache;
    }

    public function getForArea(string $area): Collection
    {
        return $this->executeQuery(function() use ($area) {
            return $this->cache->remember("widgets.area.{$area}", function() use ($area) {
                return $this->model
                    ->where('area', $area)
                    ->where('active', true)
                    ->orderBy('order')
                    ->get();
            });
        });
    }

    public function updateOrder(array $order): void
    {
        $this->executeTransaction(function() use ($order) {
            foreach ($order as $id => $position) {
                $this->model->find($id)->update(['order' => $position]);
            }
            $this->cache->tags('widgets')->flush();
        });
    }

    public function updateConfig(Widget $widget, array $config): void
    {
        $this->executeTransaction(function() use ($widget, $config) {
            $widget->update(['config' => array_merge($widget->config, $config)]);
            $this->cache->forget("widgets.area.{$widget->area}");
        });
    }

    public function toggleActive(Widget $widget): void
    {
        $this->executeTransaction(function() use ($widget) {
            $widget->update(['active' => !$widget->active]);
            $this->cache->forget("widgets.area.{$widget->area}");
        });
    }
}
