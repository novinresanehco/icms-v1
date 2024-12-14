<?php

namespace App\Services;

use App\Repositories\Contracts\MenuRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class MenuService
{
    protected MenuRepositoryInterface $menuRepository;
    
    public function __construct(MenuRepositoryInterface $menuRepository)
    {
        $this->menuRepository = $menuRepository;
    }
    
    /**
     * Create a new menu with validation
     *
     * @param array $data
     * @return int|null
     * @throws ValidationException
     */
    public function createMenu(array $data): ?int
    {
        $this->validateMenuData($data);
        return $this->menuRepository->createMenu($data);
    }
    
    /**
     * Update an existing menu with validation
     *
     * @param int $menuId
     * @param array $data
     * @return bool
     * @throws ValidationException
     */
    public function updateMenu(int $menuId, array $data): bool
    {
        $this->validateMenuData($data);
        return $this->menuRepository->updateMenu($menuId, $data);
    }
    
    /**
     * Delete a menu
     *
     * @param int $menuId
     * @return bool
     */
    public function deleteMenu(int $menuId): bool
    {
        return $this->menuRepository->deleteMenu($menuId);
    }
    
    /**
     * Get a specific menu
     *
     * @param int $menuId
     * @param bool $withItems
     * @return array|null
     */
    public function getMenu(int $menuId, bool $withItems = true): ?array
    {
        return $this->menuRepository->getMenu($menuId, $withItems);
    }
    
    /**
     * Get menu by location
     *
     * @param string $location
     * @return array|null
     */
    public function getMenuByLocation(string $location): ?array
    {
        return $this->menuRepository->getMenuByLocation($location);
    }
    
    /**
     * Get all menus
     *
     * @return Collection
     */
    public function getAllMenus(): Collection
    {
        return $this->menuRepository->getAllMenus();
    }
    
    /**
     * Validate menu data
     *
     * @param array $data
     * @return void
     * @throws ValidationException
     */
    protected function validateMenuData(array $data): void
    {
        $validator = Validator::make($data, [
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:255',
            'location' => 'required|string|max:100',
            'description' => 'nullable|string',
            'status' => 'boolean',
            'items' => 'array',
            'items.*.title' => 'required|string|max:255',
            'items.*.url' => 'required|string|max:255',
            'items.*.target' => 'string|in:_self,_blank',
            'items.*.icon_class' => 'nullable|string|max:100',
            'items.*.status' => 'boolean',
            'items.*.children' => 'array',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
    }
}
