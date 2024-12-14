<?php

namespace Database\Factories;

use App\Models\Menu;
use App\Models\MenuItem;
use Illuminate\Database\Eloquent\Factories\Factory;

class MenuItemFactory extends Factory
{
    protected $model = MenuItem::class;

    public function definition(): array
    {
        return [
            'menu_id' => Menu::factory(),
            'parent_id' => null,
            'title' => $this->faker->words(2, true),
            'url' => $this->faker->url,
            'target' => '_self',
            'icon' => 'fa fa-' . $this->faker->word,
            'class' => 'menu-item-' . $this->faker->word,
            'order' => $this->faker->numberBetween(0, 100),
            'conditions' => null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now()
        ];
    }

    public function inactive(): self
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false
        ]);
    }

    public function asChild(MenuItem $parent): self
    {
        return $this->state(fn (array $attributes) => [
            'menu_id' => $parent->menu_id,
            'parent_id' => $parent->id
        ]);
    }
}
