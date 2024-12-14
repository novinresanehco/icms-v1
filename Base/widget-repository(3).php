<?php

namespace App\Repositories;

use App\Models\Widget;
use App\Repositories\Contracts\WidgetRepositoryInterface;
use Illuminate\Support\Collection;

class WidgetRepository extends BaseRepository implements WidgetRepositoryInterface
{
    protected array $searchableFields = ['title', 'description'];
    protected array $filterableFields = ['type', 'status', 'location'];

    public function getByLocation(string $location): Collection
    {
        return Cache::remember(
            $this->getCacheKey("location.{$location}"),
            $this->cacheTTL,
            fn() => $this->model->where('location', $location)
                ->where('status', 'active')
                ->orderBy('order')
                ->get()
        );
    }

    public function updateOrder(array $order): bool
    {
        try {
            DB::beginTransaction();
            foreach ($order as $id => $position) {
                $this->model->where('id', $id)->update(['order' => $position]);
            }
            DB::commit();
            $this->clearModelCache();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            return false;
        }
    }

    public function getAvailable(): Collection
    {
        return Cache::remember(
            $this->getCacheKey('available'),
            $this->cacheTTL,
            fn() => $this->model->where('status', 'active')
                ->whereNull('location')
                ->get()
        );
    }
}
