<?php

namespace Tests\Unit;

use App\Models\Menu;
use App\Models\MenuItem;
use App\Repositories\MenuRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MenuRepositoryTest extends TestCase
{
    use RefreshDatabase;

    protected MenuRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new MenuRepository(new Menu());
    }

    public function test_can_create_menu(): void
    {
        $data = [
            'name' => 'Test Menu',
            'slug' => 'test-menu',
            'location' => 'header',
            'items' => [
                [
                    'title' => 'Item 1',
                    'url' => '/item-1',
                    'children' => [
                        [
                            'title' => 'Sub Item 1',
                            'url' => '/sub-item-1',
                        ]
                    ]
                ]
            ]
        ];

        $menuId = $this->repository->createMenu($data);
        
        $this->assertNotNull($menuId);
        $this->assertDatabaseHas('menus', [
            'id' => $menuId,
            'name' => 'Test Menu'
        ]);
        $this->assertDatabaseHas('menu_items', [
            'menu_id' => $menuId,
            'title' => 'Item 1'
        ]);
        $this->assertDatabaseHas('menu_items', [
            'menu_id' => $menuId,
            'title' => 'Sub Item 1'
        ]);
    }

    public function test_can_update_menu(): void
    {
        $menu = Menu::factory()->create();
        MenuItem::factory()->create(['menu_id' => $menu->id]);

        $data = [
            'name' => 'Updated Menu',
            'slug' => 'updated-menu',
            'location' => 'footer',
            'items' => [
                [
                    'title' => 'New Item',
                    'url' => '/new-item',
                ]
            ]
        ];

        $result = $this->repository->updateMenu($menu->id, $data);

        $this->assertTrue($result);
        $this->assertDatabaseHas('menus', [
            'id' => $menu->id,
            'name' => 'Updated Menu'
        ]);
        $this->assertDatabaseHas('menu_items', [
            'menu_id' => $menu->id,
            'title' => 'New Item'
        ]);
    }

    public function test_can_delete_menu(): void
    {
        $menu = Menu::factory()->create();
        MenuItem::factory()->create(['menu_id' => $menu->id]);

        $result = $this->repository->deleteMenu($menu->id);

        $this->assertTrue($result);
        $this->assertSoftDeleted('menus', ['id' => $menu->id]);
        $this->assertSoftDeleted('menu_items', ['menu_id' => $menu->id]);
    }

    public function test_can_get_menu_by_location(): void
    {
        $menu = Menu::factory()->create(['location' => 'header']);
        MenuItem::factory()->create(['menu_id' => $menu->id]);

        $result = $this->repository->getMenuByLocation('header');

        $this->assertNotNull($result);
        $this->assertEquals($menu->id, $result['id']);
        $this->assertArrayHasKey('active_items', $result);
    }
}
