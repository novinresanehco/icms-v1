<?php

namespace App\Repositories;

use App\Models\Menu;
use App\Core\Repositories\BaseRepository;
use Illuminate\Database\Eloquent\Collection;

class MenuRepository extends BaseRepository
{
    public function __construct(Menu $model)
    {
        $this->model = $model;
        parent::__construct();
    }

    public function findByLocation(string $location): Collection
    {
        return $this->executeWithCache(__FUNCTION__, [$location], function () use ($location) {
            return $this->model->where('location', $location)
                             ->where('status', 'active')
                             ->orderBy('order')
                             ->get();
        });
    }

    public function updateOrder(array $items): bool
    {
        foreach ($items as $order => $id) {
            $this->update($id, ['order' => $order]);
        }
        
        $this->clearCache();
        return true;
    }

    public function findWithChildren(int $id): ?Menu
    {
        return $this->executeWithCache(__FUNCTION__, [$id], function () use ($id) {
            return $this->model->with('children')
                             ->where('id', $id)
                             ->first();
        });
    }

    public function createMenuItem(array $data, ?int $parentId = null): Menu
    {
        $data['parent_id'] = $parentId;
        $data['order'] = $this->getNextOrder($parentId);
        
        $menu = $this->create($data);
        $this->clearCache();
        return $menu;
    }

    protected function getNextOrder(?int $parentId): int
    {
        return $this->model->where('parent_id', $parentId)
                          ->max('order') + 1;
    }
}
