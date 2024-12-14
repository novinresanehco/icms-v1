<?php

namespace Tests\Unit\Repositories;

use Tests\TestCase;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Repositories\MenuRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

class MenuRepositoryTest extends TestCase
{
    use RefreshDatabase;

    protected MenuRepository $repository;
    protected Menu $menu;
    protected MenuItem $menuItem;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->menu = new Menu();
        $this->menuItem = new MenuItem();
        $this->repository = new MenuRepository($this->menu, $this->menuItem);
    }

    public function testCanCreateMenu(): void
    {
        $data = [
            'name' => 'Main Menu',
            'location' => 'header',
            'description' => 'Main navigation menu',
            'is_active' => true
        ];

        $menu = $this->repository->create($data);

        $this->assertDatabaseHas('menus', $data);
        $this->assertEquals($data['name'], $menu->name);
        $this->assertEquals($data['location'], $menu->location);
    }

    public function testCanUpdateMenu(): void
    {
        $menu = Menu::factory()->create();
        
        $data = [
            'name' => 'Updated Menu',
            'description' => 'Updated description'
        ];

        $updatedMenu = $this->repository->update($menu->id, $data);

        $this->assertDatabaseHas('menus', [
            'id' => $menu->id,
            'name' => $data['name']
        ]);
        $this->assertEquals($data['name'], $updatedMenu->name);
    }

    public function testCanDeleteMenu(): void
    {
        $menu = Menu::factory()->create();

        $this->repository->delete($menu->id);

        $this->assertSoftDeleted('menus', ['id' => $menu->id]);
    }

    public function testCanFindMenuByLocation(): void
    {
        $menu = Menu::factory()->create(['location' => 'header']);

        $found = $this->repository->findByLocation('header');

        $this->assertEquals($menu->id, $found->id);
    }

    public function testCanGetActiveMenus(): void
    {
        Menu::factory()->count(3)->create(['is_active' => true]);
        Menu::factory()->create(['is_active' => false]);

        $activeMenus = $this->repository->getActive();

        $this->assertCount(3, $activeMenus);
        $this->assertTrue($activeMenus->every(fn($menu) => $menu->is_active));
    }

    public function testCanAddMenuItem(): void
    {
        $menu = Menu::factory()->create();
        
        $itemData = [
            'title' => 'Home',
            'url' => '/',
            'order' => 1
        ];

        $menuItem = $this->repository->addMenuItem($menu, $itemData);

        $this->assertDatabaseHas('menu_items', [
            'menu_id' => $menu->id,
            'title' => $itemData['title']
        ]);
        $this->assertEquals($itemData['title'], $menuItem->title);
    }

    public function testCanUpdateMenuItem(): void
    {
        $menu = Menu::factory()->create();
        $menuItem = MenuItem::factory()->create(['menu_id' => $menu->id]);
        
        $updateData = [
            'title' => 'Updated Title',
            'url' => '/updated'
        ];

        $updatedItem = $this->repository->updateMenuItem($menuItem->id, $updateData);

        $this->assertDatabaseHas('menu_items', [
            'id' => $menuItem->id,
            'title' => $updateData['title']
        ]);
        $this->assertEquals($updateData['title'], $updatedItem->title);
    }

    public function testCanDeleteMenuItem(): void
    {
        $menu = Menu::factory()->create();
        $menuItem = MenuItem::factory()->create(['menu_id' => $menu->id]);

        $this->repository->deleteMenuItem($menuItem->id);

        $this->assertDatabaseMissing('menu_items', ['id' => $menuItem->id]);
    }

    public function testCanReorderMenuItems(): void
    {
        $menu = Menu::factory()->create();
        $items = MenuItem::factory()->count(3)->create(['menu_id' => $menu->id]);
        
        $newOrder = $items->pluck('id')->reverse()->toArray();

        $this->repository->reorderItems($newOrder);

        foreach ($newOrder as $order => $id) {
            $this->assertDatabaseHas('menu_items', [
                'id' => $id,
                'order' => $order
            ]);
        }
    }

    public function testCacheIsInvalidatedOnUpdate(): void
    {
        $menu = Menu::factory()->create(['location' => 'header']);
        
        Cache::shouldReceive('tags')
            ->with(['menus'])
            ->once()
            ->andReturnSelf();
            
        Cache::shouldReceive('flush')->once();
        Cache::shouldReceive('forget')
            ->with("menu.location.{$menu->location}")
            ->once();

        $this->repository->update($menu->id, ['name' => 'Updated Menu']);
    }
}
