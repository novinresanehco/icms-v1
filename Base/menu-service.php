<?php

namespace App\Services;

use App\Models\Menu;
use App\Repositories\Contracts\MenuRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class MenuService
{
    public function __construct(
        protected MenuRepositoryInterface $menuRepository
    ) {}

    public function createMenu(array $data): Menu
    {
        $this->validateMenuData($data);
        return $this->menuRepository->create($data);
    }

    public function updateMenu(int $id, array $data): Menu
    {
        $this->validateMenuData($data, $id);
        return $this->menuRepository->update($id, $data);
    }

    public function deleteMenu(int $id): bool
    {
        return $this->menuRepository->delete($id);
    }

    public function getMenuByLocation(string $location): ?Menu
    {
        return $this->menuRepository->findByLocation($location);
    }

    public function getActiveMenus(): Collection
    {
        return $this->menuRepository->getActive();
    }

    public function addMenuItem(int $menuId, array $data): Menu
    {
        $menu = $this->menuRepository->find($menuId);
        $this->validateMenuItemData($data);
        $this->menuRepository->addMenuItem($menu, $data);
        return $menu->fresh();
    }

    public function updateMenuItem(int $itemId, array $data): Menu
    {
        $this->validateMenuItemData($data);
        $item = $this->menuRepository->updateMenuItem($itemId, $data);
        return $item->menu->fresh();
    }

    public function deleteMenuItem(int $itemId): bool
    {
        return $this->menuRepository->deleteMenuItem($itemId);
    }

    public function reorderMenuItems(array $items): bool
    {
        return $this->menuRepository->reorderItems($items);
    }

    protected function validateMenuData(array $data, ?int $id = null): void
    {
        $rules = [
            'name' => 'required|string|max:255',
            'location' => 'required|string|max:255|unique:menus,location' . ($id ? ",$id" : ''),
            'description' => 'nullable|string',
            'settings' => 'nullable|array',
            'is_active' => 'boolean'
        ];

        $validator = Validator::make($data, $rules);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
    }

    protected function validateMenuItemData(array $data): void
    {
        $rules = [
            'parent_id' => 'nullable|exists:menu_items,id',
            'title' => 'required|string|max:255',
            'url' => 'required|string|max:255',
            'target' => 'string|in:_self,_blank',
            'icon' => 'nullable|string|max:255',
            'class' => 'nullable|string|max:255',
            'order' => 'integer|min:0',
            'conditions' => 'nullable|array',
            'is_active' => 'boolean'
        ];

        $validator = Validator::make($data, $rules);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
    }
}
