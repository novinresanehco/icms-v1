<?php

namespace App\Repositories;

use App\Models\Menu;
use App\Models\MenuItem;
use App\Repositories\Contracts\MenuRepositoryInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class MenuRepository extends BaseRepository implements MenuRepositoryInterface
{
    protected function getModel(): Model
    {
        return new Menu();
    }

    public function findByLocation(string $location): ?Menu
    {
        return $this->model->where('location', $location)
            ->with(['items' => function ($query) {
                $query->orderBy('order')->with('children');
            }])
            ->first();
    }

    public function getActiveMenus(): Collection
    {
        return $this->model->where('status', 'active')
            ->with(['items' => function ($query) {
                $query->orderBy('order');
            }])
            ->get();
    }

    public function createWithItems(array $data, array $items): Menu
    {
        \DB::beginTransaction();
        
        try {
            $menu = $this->model->create($data);
            $this->createMenuItems($menu->id, $items);
            
            \DB::commit();
            return $menu->load('items');
        } catch (\Exception $e) {
            \DB::rollBack();
            throw $e;
        }
    }

    public function updateWithItems(int $id, array $data, array $items): bool
    {
        \DB::beginTransaction();
        
        try {
            $menu = $this->model->findOrFail($id);
            $menu->update($data);
            
            MenuItem::where('menu_id', $id)->delete();
            $this->createMenuItems($id, $items);
            
            \DB::commit();
            return true;
        } catch (\Exception $e) {
            \DB::rollBack();
            throw $e;
        }
    }

    public function reorderItems(int $menuId, array $order): bool
    {
        \DB::beginTransaction();
        
        try {
            foreach ($order as $position => $itemId) {
                MenuItem::where('id', $itemId)
                    ->where('menu_id', $menuId)
                    ->update(['order' => $position]);
            }
            
            \DB::commit();
            return true;
        } catch (\Exception $e) {
            \DB::rollBack();
            return false;
        }
    }

    public function addItem(int $menuId, array $itemData): bool
    {
        $menu = $this->model->findOrFail($menuId);
        
        $itemData['order'] = MenuItem::where('menu_id', $menuId)
            ->where('parent_id', $itemData['parent_id'] ?? null)
            ->max('order') + 1;
            
        return $menu->items()->create($itemData) ? true : false;
    }

    public function removeItem(int $menuId, int $itemId): bool
    {
        return MenuItem::where('menu_id', $menuId)
            ->where('id', $itemId)
            ->delete() > 0;
    }

    public function updateItem(int $menuId, int $itemId, array $data): bool
    {
        return MenuItem::where('menu_id', $menuId)
            ->where('id', $itemId)
            ->update($data) > 0;
    }

    public function getMenuTree(int $menuId): array
    {
        $menu = $this->model->with(['items' => function ($query) {
            $query->whereNull('parent_id')->with('children');
        }])->findOrFail($menuId);

        return $this->buildTree($menu->items);
    }

    public function duplicateMenu(int $id, string $newName): Menu
    {
        \DB::beginTransaction();
        
        try {
            $originalMenu = $this->model->with('items')->findOrFail($id);
            
            $newMenu = $this->model->create([
                'name' => $newName,
                'location' => $originalMenu->location . '_copy',
                'status' => 'inactive'
            ]);
            
            $this->duplicateMenuItems($originalMenu->items, $newMenu->id);
            
            \DB::commit();
            return $newMenu->load('items');
        } catch (\Exception $e) {
            \DB::rollBack();
            throw $e;
        }
    }

    protected function createMenuItems(int $menuId, array $items, ?int $parentId = null): void
    {
        foreach ($items as $order => $item) {
            $menuItem = MenuItem::create([
                'menu_id' => $menuId,
                'parent_id' => $parentId,
                'title' => $item['title'],
                'url' => $item['url'],
                'target' => $item['target'] ?? '_self',
                'class' => $item['class'] ?? null,
                'order' => $order
            ]);

            if (!empty($item['children'])) {
                $this->createMenuItems($menuId, $item['children'], $menuItem->id);
            }
        }
    }

    protected function duplicateMenuItems(Collection $items, int $newMenuId, ?int $parentId = null): void
    {
        foreach ($items as $item) {
            $newItem = MenuItem::create([
                'menu_id' => $newMenuId,
                'parent_id' => $parentId,
                'title' => $item->title,
                'url' => $item->url,
                'target' => $item->target,
                'class' => $item->class,
                'order' => $item->order
            ]);

            if ($item->children->count() > 0) {
                $this->duplicateMenuItems($item->children, $newMenuId, $newItem->id);
            }
        }
    }

    protected function buildTree(Collection $items): array
    {
        $tree = [];
        
        foreach ($items as $item) {
            $node = $item->toArray();
            
            if ($item->children->count() > 0) {
                $node['children'] = $this->buildTree($item->children);
            }
            
            $tree[] = $node;
        }
        
        return $tree;
    }
}
