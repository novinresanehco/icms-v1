<?php

namespace Database\Factories;

use App\Models\MenuItem;
use Illuminate\Database\Eloquent\Factories\Factory;

class MenuItemFactory extends Factory
{
    protected $model = MenuItem::class;

    public function definition(): array
    {
        return [
            'title' => $this->faker->words(2, true),
            'url' => $this->faker->url(),
            'target' => '_self',
            'icon_class' => 'fa fa-' . $this->faker->word(),
            'order' => $this->faker->numberBetween(0, 10),
            'status' => true,
        ];
    }
}
