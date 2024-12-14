<?php

namespace App\Core\Repositories;

use App\Core\Repositories\Contracts\MenuRepositoryInterface;
use App\Models\Menu;
use App\Models\MenuItem;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class MenuRepository implements MenuRepositoryInterface
{
    protected Menu $model;
    protected MenuItem $itemModel;

    public function __construct(Menu $model, MenuItem $itemModel)
    {
        $this->model = $model;
        $this->itemModel = $itemModel;
    }

    public function find(int $id): ?Menu
    {
        return $this->model->with('items')->find($id);
    }

    public function findByLocation(string $location): ?Menu
    {
        return $this->model->where('location', $location)
            ->with(['items' => function($query) {
                $query->orderBy('order')->with('children');
            }])
            ->first();
    }

    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return $this->model->withCount('items')->paginate($perPage);
    }

    public function create(array $data): Menu
    {
        DB::beginTransaction();
        try {
            $menu = $this->model->create($data);
            
            if (!empty($data['items'])) {
                foreach ($data['items'] as $item) {
                    $this->addItem($menu->id, $item);
                }
            }
            
            DB::commit();
            return $menu;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function update(int $id, array $data): Menu
    {
        DB::beginTransaction();
        try {
            $menu = $this->model->findOrFail($id);
            $menu->update($data);
            
            if (isset($data['items'])) {
                $menu->items()->delete();
                foreach ($data['items'] as $item) {
                    $this->addItem($menu->id, $item);
                }
            }
            
            DB::commit();
            return $menu;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function delete(int $id): bool
    {
        DB::beginTransaction();
        try {
            $menu = $this->model->findOrFail($id);
            $menu->items()->delete();
            $menu->delete();
            
            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function getItems(int $menuId): Collection
    {
        return $this->itemModel->where('menu_id', $menuId)
            ->whereNull('parent_id')
            ->orderBy('order')
            ->with('children')
            ->get();
    }

    public function addItem(int $menuId, array $itemData): bool
    {
        try {
            $itemData['menu_id'] = $menuId;
            $itemData['order'] = $itemData['order'] ?? $this->getNextItemOrder($menuId);
            
            $item = $this->itemModel->create($itemData);
            
            if (!empty($itemData['children'])) {
                foreach ($itemData['children'] as $child) {
                    $child['parent_id'] = $item->id;
                    $this->addItem($menuId, $child);
                }
            }
            
            return true;
        } catch (\Exception $e) {
            report($e);
            return false;
        }
    }

    public function updateItem(int $itemId, array $itemData): bool
    {
        try {
            $item = $this->itemModel->findOrFail($itemId);
            return $item->update($itemData);
        } catch (\Exception $e) {
            report($e);
            return false;
        }
    }

    public function deleteItem(int $itemId): bool
    {
        try {
            return (bool) $this->itemModel->where('id', $itemId)
                ->orWhere('parent_id', $itemId)
                ->delete();
        } catch (\Exception $e) {
            report($e);
            return false;
        }
    }

    public function reorderItems(int $menuId, array $order): bool
    {
        DB::beginTransaction();
        try {
            foreach ($order as $position => $itemId) {
                $this->itemModel->where('id', $itemId)
                    ->where('menu_id', $menuId)
                    ->update(['order' => $position]);
            }
            
            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            report($e);
            return false;
        }
    }

    public function getLocations(): Collection
    {
        return $this->model->select('location')->distinct()->get();
    }

    protected function getNextItemOrder(int $menuId): int
    {
        return $this->itemModel->where('menu_id', $menuId)->max('order') + 1;
    }
}
