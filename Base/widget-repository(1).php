<?php

namespace App\Repositories;

use App\Models\Widget;
use App\Repositories\Contracts\WidgetRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class WidgetRepository extends BaseRepository implements WidgetRepositoryInterface
{
    protected array $searchableFields = ['name', 'description'];
    protected array $filterableFields = ['type', 'status', 'location'];

    public function getActiveByLocation(string $location): Collection
    {
        return Cache::tags(['widgets'])->remember("widgets.{$location}", 3600, function() use ($location) {
            return $this->model
                ->where('location', $location)
                ->where('status', 'active')
                ->orderBy('order')
                ->get();
        });
    }

    public function updateOrder(array $order): bool
    {
        try {
            foreach ($order as $id => $position) {
                $this->update($id, ['order' => $position]);
            }
            
            Cache::tags(['widgets'])->flush();
            return true;
        } catch (\Exception $e) {
            \Log::error('Error updating widget order: ' . $e->getMessage());
            return false;
        }
    }

    public function renderWidget(Widget $widget, array $data = []): string
    {
        try {
            $renderer = app('App\Services\Widget\WidgetRenderer');
            return $renderer->render($widget, $data);
        } catch (\Exception $e) {
            \Log::error('Widget rendering error: ' . $e->getMessage());
            return '';
        }
    }

    public function cloneWidget(int $id): ?Widget
    {
        try {
            $widget = $this->findById($id);
            $clone = $widget->replicate();
            $clone->name = $widget->name . ' (Copy)';
            $clone->status = 'inactive';
            $clone->save();
            
            return $clone;
        } catch (\Exception $e) {
            \Log::error('Error cloning widget: ' . $e->getMessage());
            return null;
        }
    }
}
