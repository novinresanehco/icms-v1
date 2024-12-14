<?php

namespace App\Repositories;

use App\Models\Widget;
use App\Core\Repositories\BaseRepository;
use Illuminate\Database\Eloquent\Collection;

class WidgetRepository extends BaseRepository
{
    public function __construct(Widget $model)
    {
        $this->model = $model;
        parent::__construct();
    }

    public function findByArea(string $area): Collection
    {
        return $this->executeWithCache(__FUNCTION__, [$area], function () use ($area) {
            return $this->model->where('area', $area)
                             ->where('status', 'active')
                             ->orderBy('order')
                             ->get();
        });
    }

    public function updatePositions(array $positions): bool
    {
        foreach ($positions as $position) {
            $this->update($position['id'], [
                'area' => $position['area'],
                'order' => $position['order']
            ]);
        }
        
        $this->clearCache();
        return true;
    }

    public function findByType(string $type): Collection
    {
        return $this->executeWithCache(__FUNCTION__, [$type], function () use ($type) {
            return $this->model->where('type', $type)
                             ->where('status', 'active')
                             ->orderBy('created_at', 'desc')
                             ->get();
        });
    }

    public function duplicate(int $id): ?Widget
    {
        $widget = $this->find($id);
        if (!$widget) {
            return null;
        }

        $copy = $this->create([
            'name' => $widget->name . ' (Copy)',
            'type' => $widget->type,
            'settings' => $widget->settings,
            'area' => $widget->area,
            'status' => 'draft'
        ]);

        $this->clearCache();
        return $copy;
    }
}
