<?php

namespace App\Core\Repositories;

use App\Core\Repositories\Contracts\MenuRepositoryInterface;
use App\Models\Menu;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

class MenuRepository extends BaseRepository implements MenuRepositoryInterface
{
    public function __construct(Menu $model)
    {
        parent::__construct($model);
    }

    public function getMenuTree(string $location): Collection
    {
        return Cache::tags(['menus', "location:{$location}"])->remember(
            "menu:tree:{$location}",
            now()->addDay(),
            fn () => $this->model
                ->where('location', $location)
                ->whereNull('parent_id')
                ->with(['children' => function ($query) {
                    $query->orderBy('order');
                }])
                ->orderBy('order')
                ->get()
        );
    }

    public function updateMenuItem(int $id, array $data): bool
    {
        $result = $this->update($id, $data);
        
        if ($result) {
            Cache::tags(['menus'])->flush();
        }
        
        return $result;
    }

    public function reorderMenuItems(array $order): bool
    {
        $success = true;

        foreach ($order as $id => $position) {
            $success = $success && $this->update($id, [
                'order' => $position['order'],
                'parent_id' => $position['parent_id'] ?? null
            ]);
        }

        if ($success) {
            Cache::tags(['menus'])->flush();
        }

        return $success;
    }

    public function getActiveMenus(): Collection
    {
        return Cache::tags(['menus', 'active'])->remember(
            'menus:active',
            now()->addHours(6),
            fn () => $this->model
                ->where('status', 'active')
                ->orderBy('location')
                ->orderBy('order')
                ->get()
        );
    }

    public function createMenuItem(array $data): Menu
    {
        if (!isset($data['order'])) {
            $data['order'] = $this->getNextOrder($data['location'], $data['parent_id'] ?? null);
        }

        $menuItem = $this->create($data);
        Cache::tags(['menus'])->flush();
        
        return $menuItem;
    }

    protected function getNextOrder(string $location, ?int $parentId): int
    {
        return $this->model
            ->where('location', $location)
            ->where('parent_id', $parentId)
            ->max('order') + 1;
    }
}
