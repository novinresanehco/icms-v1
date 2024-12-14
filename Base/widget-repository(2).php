<?php

namespace App\Repositories;

use App\Models\Widget;
use App\Repositories\Contracts\WidgetRepositoryInterface;
use Illuminate\Support\Collection;

class WidgetRepository extends BaseRepository implements WidgetRepositoryInterface
{
    protected array $searchableFields = ['name', 'description'];
    protected array $filterableFields = ['type', 'status', 'position'];
    protected array $relationships = ['settings'];

    public function getByPosition(string $position): Collection 
    {
        return Cache::remember(
            $this->getCacheKey("position.{$position}"),
            $this->cacheTTL,
            fn() => $this->model->with($this->relationships)
                ->where('position', $position)
                ->where('status', 'active')
                ->orderBy('order')
                ->get()
        );
    }

    public function updateOrder(array $order): void
    {
        try {
            DB::beginTransaction();
            
            foreach ($order as $position => $widgetIds) {
                foreach ($widgetIds as $order => $id) {
                    $this->model->where('id', $id)->update([
                        'position' => $position,
                        'order' => $order
                    ]);
                }
            }
            
            DB::commit();
            $this->clearModelCache();
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw new RepositoryException("Failed to update widget order: {$e->getMessage()}");
        }
    }

    public function updateSettings(int $id, array $settings): Widget
    {
        $widget = $this->findOrFail($id);
        $widget->settings()->delete();
        
        foreach ($settings as $key => $value) {
            $widget->settings()->create([
                'key' => $key,
                'value' => $value
            ]);
        }
        
        $this->clearModelCache();
        return $widget->load('settings');
    }
}
