<?php

namespace App\Repositories;

use App\Models\Menu;
use App\Repositories\Contracts\MenuRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

class MenuRepository extends BaseRepository implements MenuRepositoryInterface
{
    protected array $searchableFields = ['name', 'description'];
    protected array $filterableFields = ['location', 'status'];

    public function getMenuTree(string $location): Collection
    {
        $cacheKey = 'menus.tree.' . $location;

        return Cache::tags(['menus'])->remember($cacheKey, 3600, function() use ($location) {
            return $this->model
                ->where('location', $location)
                ->where('status', 'active')
                ->whereNull('parent_id')
                ->with(['children' => function($query) {
                    $query->where('status', 'active')
                        ->orderBy('sort_order');
                }])
                ->orderBy('sort_order')
                ->get();
        });
    }

    public function updateMenuOrder(array $items): bool
    {
        try {
            foreach ($items as $index => $item) {
                $this->update($item['id'], [
                    'parent_id' => $item['parent_id'] ?? null,
                    'sort_order' => $index
                ]);
            }

            Cache::tags(['menus'])->flush();

            return true;
        } catch (\Exception $e) {
            \Log::error('Error updating menu order: ' . $e->getMessage());
            return false;
        }
    }

    public function getByLocation(string $location): Collection
    {
        $cacheKey = 'menus.location.' . $location;

        return Cache::tags(['menus'])->remember($cacheKey, 3600, function() use ($location) {
            return $this->model
                ->where('location', $location)
                ->where('status', 'active')
                ->orderBy('sort_order')
                ->get();
        });
    }

    public function create(array $data): Menu
    {
        if (!isset($data['sort_order'])) {
            $data['sort_order'] = $this->getNextSortOrder($data['location']);
        }

        $menu = parent::create($data);
        Cache::tags(['menus'])->flush();
        return $menu;
    }

    public function update(int $id, array $data): Menu
    {
        $menu = parent::update($id, $data);
        Cache::tags(['menus'])->flush();
        return $menu;
    }

    public function delete(int $id): bool
    {
        try {
            $menu = $this->find($id);
            
            // Reorder children
            $menu->children()->update(['parent_id' => $menu->parent_id]);
            
            $result = parent::delete($id);
            
            if ($result) {
                Cache::tags(['menus'])->flush();
            }
            
            return $result;
        } catch (\Exception $e) {
            \Log::error('Error deleting menu: ' . $e->getMessage());
            return false;
        }
    }

    protected function getNextSortOrder(string $location): int
    {
        return $this->model
            ->where('location', $location)
            ->max('sort_order') + 1;
    }
}
