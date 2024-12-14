<?php

namespace App\Repositories;

use App\Models\Menu;
use App\Core\Repositories\AbstractRepository;
use Illuminate\Support\Collection;

class MenuRepository extends AbstractRepository
{
    protected array $with = ['items.children'];

    public function getByLocation(string $location): ?Menu
    {
        return $this->executeQuery(function() use ($location) {
            return $this->model->where('location', $location)
                ->with(['items' => function($query) {
                    $query->whereNull('parent_id')->orderBy('position');
                }])
                ->first();
        });
    }

    public function updateItems(int $menuId, array $items): void
    {
        $this->beginTransaction();
        try {
            $menu = $this->findOrFail($menuId);
            $menu->items()->delete();
            
            foreach ($items as $position => $item) {
                $this->createMenuItem($menu, $item, $position);
            }
            
            $this->commit();
        } catch (\Exception $e) {
            $this->rollback();
            throw $e;
        }
    }

    protected function createMenuItem(Menu $menu, array $item, int $position, ?int $parentId = null): void
    {
        $menuItem = $menu->items()->create([
            'title' => $item['title'],
            'url' => $item['url'],
            'target' => $item['target'] ?? '_self',
            'position' => $position,
            'parent_id' => $parentId
        ]);

        if (!empty($item['children'])) {
            foreach ($item['children'] as $childPosition => $child) {
                $this->createMenuItem($menu, $child, $childPosition, $menuItem->id);
            }
        }
    }
}
