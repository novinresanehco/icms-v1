<?php

namespace Database\Factories;

use App\Models\Menu;
use Illuminate\Database\Eloquent\Factories\Factory;

class MenuFactory extends Factory
{
    protected $model = Menu::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->words(2, true),
            'location' => $this->faker->unique()->word,
            'description' => $this->faker->sentence,
            'settings' => [
                'cache_duration' => 3600,
                'max_depth' => 3
            ],
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
}
