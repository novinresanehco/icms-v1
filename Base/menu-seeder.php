<?php

namespace Database\Seeders;

use App\Models\Menu;
use App\Models\MenuItem;
use Illuminate\Database\Seeder;

class MenuSeeder extends Seeder
{
    public function run(): void
    {
        $headerMenu = Menu::factory()->create([
            'name' => 'Header Menu',
            'slug' => 'header-menu',
            'location' => 'header',
        ]);

        $this->createMenuItems($headerMenu);

        $footerMenu = Menu::factory()->create([
            'name' => 'Footer Menu',
            'slug' => 'footer-menu',
            'location' => 'footer',
        ]);

        $this->createMenuItems($footerMenu);
    }

    protected function createMenuItems(Menu $menu): void
    {
        // Create parent items
        $parentItems = MenuItem::factory(3)->create([
            'menu_id' => $menu->id,
            'parent_id' => null,
        ]);

        // Create children for each parent
        foreach ($parentItems as $parent) {
            MenuItem::factory(2)->create([
                'menu_id' => $menu->id,
                'parent_id' => $parent->id,
            ]);
        }
    }
}
