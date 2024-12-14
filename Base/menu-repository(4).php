<?php

namespace App\Repositories;

use App\Models\Menu;
use App\Repositories\Contracts\MenuRepositoryInterface;
use Illuminate\Support\Collection;

class MenuRepository extends BaseRepository implements MenuRepositoryInterface
{
    protected array $searchableFields = ['name', 'location'];
    protected array $filterableFields = ['status', 'location'];

    public function __construct(Menu $model)
    {
        parent::__construct($model);
    }

    public function getByLocation(string $location): ?Menu
    {
        try {
            return Cache::remember(
                $this->getCacheKey("location.{$location}"),
                $this->cacheTTL,
                fn() => $this->model->with(['items' => function ($query) {
                    $query->orderBy('order')->with('children');
                }])
                ->where('location', $location)
                ->where('status', 'active')
                ->first()
            );
        } catch (\Exception $e) {
            Log::error('Failed to get menu by location: ' . $e->getMessage());
            return null;
        }
    }

    public function updateItems(int $menuId, array $items): bool
    {
        try {
            DB::beginTransaction();

            $menu = $this->find($menuId);
            if (!$menu) {
                throw new \Exception('Menu not found');
            }

            $menu->items()->delete();
            $this->createMenuItems($menu, $items);

            DB::commit();
            $this->clearModelCache();

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update menu items: ' . $e->getMessage());
            return false;
        }
    }

    protected function createMenuItems(Menu $menu, array $items, ?int $parentId = null): void
    {
        foreach ($items as $order => $item) {
            $menuItem = $menu->items()->create([
                'parent_id' => $parentId,
                'title' => $item['title'],
                'url' => $item['url'],
                'target' => $item['target'] ?? '_self',
                'order' => $order + 1,
                'icon' => $item['icon'] ?? null,
                'class' => $item['class'] ?? null
            ]);

            if (!empty($item['children'])) {
                $this->createMenuItems($menu, $item['children'], $menuItem->id);
            }
        }
    }
}
