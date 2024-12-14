<?php

namespace App\Core\Repositories;

use App\Core\Repositories\Contracts\WidgetRepositoryInterface;
use App\Models\Widget;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

class WidgetRepository extends BaseRepository implements WidgetRepositoryInterface
{
    public function __construct(Widget $model)
    {
        parent::__construct($model);
    }

    public function getActiveWidgets(string $area): Collection
    {
        return Cache::tags(['widgets', "area:{$area}"])->remember(
            "widgets:active:{$area}",
            now()->addHours(1),
            fn () => $this->model
                ->where('area', $area)
                ->where('status', 'active')
                ->orderBy('order')
                ->get()
        );
    }

    public function updateWidgetOrder(array $order): bool
    {
        $success = true;

        foreach ($order as $id => $position) {
            $success = $success && $this->update($id, [
                'order' => $position
            ]);
        }

        if ($success) {
            Cache::tags(['widgets'])->flush();
        }

        return $success;
    }

    public function createWidget(array $data): Widget
    {
        if (!isset($data['order'])) {
            $data['order'] = $this->getNextOrder($data['area']);
        }

        $widget = $this->create($data);
        Cache::tags(['widgets'])->flush();
        
        return $widget;
    }

    public function getWidgetsByType(string $type): Collection
    {
        return Cache::tags(['widgets', "type:{$type}"])->remember(
            "widgets:type:{$type}",
            now()->addHours(6),
            fn () => $this->model
                ->where('type', $type)
                ->where('status', 'active')
                ->orderBy('area')
                ->orderBy('order')
                ->get()
        );
    }

    public function updateWidgetSettings(int $id, array $settings): bool
    {
        $widget = $this->find($id);
        if (!$widget) {
            return false;
        }

        $widget->settings = array_merge($widget->settings ?? [], $settings);
        $success = $widget->save();

        if ($success) {
            Cache::tags(['widgets'])->flush();
        }

        return $success;
    }

    protected function getNextOrder(string $area): int
    {
        return $this->model
            ->where('area', $area)
            ->max('order') + 1;
    }
}
