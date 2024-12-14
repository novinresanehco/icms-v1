<?php

namespace Database\Factories;

use App\Core\Models\Template;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class TemplateFactory extends Factory
{
    protected $model = Template::class;

    public function definition(): array
    {
        $name = $this->faker->unique()->words(3, true);
        
        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => $this->faker->sentence(),
            'content' => $this->faker->randomHtml(),
            'type' => $this->faker->randomElement(['page', 'partial', 'layout']),
            'category' => $this->faker->randomElement(['blog', 'news', 'product']),
            'status' => $this->faker->randomElement(['active', 'inactive', 'draft']),
            'author_id' => \App\Models\User::factory(),
            'variables' => ['title', 'content', 'sidebar'],
            'settings' => [
                'cache_enabled' => true,
                'cache_duration' => 3600
            ],
            'version' => '1.0.0'
        ];
    }

    public function active(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'active'
        ]);
    }

    public function draft(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'draft'
        ]);
    }
}
