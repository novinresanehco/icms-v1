<?php

namespace App\Repositories;

use App\Models\Menu;
use App\Repositories\Contracts\MenuRepositoryInterface;
use Illuminate\Support\Collection;

class MenuRepository extends BaseRepository implements MenuRepositoryInterface
{
    protected array $searchableFields = ['name', 'location'];
    protected array $filterableFields = ['status', 'location'];
    protected array $relationships = ['items'];

    public function __construct(Menu $model)
    {
        parent::__construct($model);
    }

    public function getByLocation(string $location): Collection
    {
        return Cache::remember(
            $this->getCacheKey("location.{$location}"),
            $this->cacheTTL,
            fn() => $this->model->with('items.children')->where('location', $location)->get()
        );
    }

    public function updateMenuItems(int $menuId, array $items): Menu
    {
        try {
            DB::beginTransaction();
            
            $menu = $this->findOrFail($menuId);
            $menu->items()->delete();
            
            foreach ($items as $order => $item) {
                $this->createMenuItem($menu->id, $item, null, $order);
            }
            
            DB::commit();
            $this->clearModelCache();
            return $menu->load('items');
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw new RepositoryException("Failed to update menu items: {$e->getMessage()}");
        }
    }

    protected function createMenuItem(int $menuId, array $data, ?int $parentId, int $order): void
    {
        $item = MenuItem::create([
            'menu_id' => $menuId,
            'parent_id' => $parentId,
            'title' => $data['title'],
            'url' => $data['url'],
            'order' => $order
        ]);

        if (!empty($data['children'])) {
            foreach ($data['children'] as $childOrder => $child) {
                $this->createMenuItem($menuId, $child, $item->id, $childOrder);
            }
        }
    }
}
