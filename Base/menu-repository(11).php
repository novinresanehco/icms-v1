<?php

namespace App\Core\Repositories;

use App\Models\Menu;
use App\Core\Services\Cache\CacheService;
use Illuminate\Support\Collection;

class MenuRepository extends AdvancedRepository
{
    protected $model = Menu::class;
    protected $cache;

    public function __construct(CacheService $cache)
    {
        parent::__construct();
        $this->cache = $cache;
    }

    public function getStructure(string $location): Collection
    {
        return $this->executeQuery(function() use ($location) {
            return $this->cache->remember("menu.{$location}", function() use ($location) {
                return $this->model
                    ->where('location', $location)
                    ->with(['items' => function($query) {
                        $query->orderBy('order')->with('children');
                    }])
                    ->first()
                    ->items ?? collect();
            });
        });
    }

    public function updateStructure(Menu $menu, array $items): void
    {
        $this->executeTransaction(function() use ($menu, $items) {
            $this->updateMenuItems($menu, $items);
            $this->cache->forget("menu.{$menu->location}");
        });
    }

    protected function updateMenuItems(Menu $menu, array $items, ?int $parentId = null): void
    {
        foreach ($items as $order => $item) {
            $menuItem = $menu->items()->updateOrCreate(
                ['id' => $item['id'] ?? null],
                [
                    'title' => $item['title'],
                    'url' => $item['url'],
                    'parent_id' => $parentId,
                    'order' => $order
                ]
            );

            if (!empty($item['children'])) {
                $this->updateMenuItems($menu, $item['children'], $menuItem->id);
            }
        }
    }
}
