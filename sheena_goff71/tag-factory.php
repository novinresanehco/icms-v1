<?php

namespace Database\Factories;

use App\Core\Tag\Models\Tag;
use Illuminate\Database\Eloquent\Factories\Factory;

class TagFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Tag::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->words(3, true),
            'description' => $this->faker->optional()->sentence,
            'meta_title' => $this->faker->optional()->words(6, true),
            'meta_description' => $this->faker->optional()->sentence,
        ];
    }

    /**
     * Indicate that the tag is for SEO content.
     */
    public function seo(): self
    {
        return $this->state(fn (array $attributes) => [
            'meta_title' => $this->faker->words(6, true),
            'meta_description' => $this->faker->sentence,
        ]);
    }

    /**
     * Indicate that the tag is for a specific category.
     */
    public function category(string $category): self
    {
        return $this->state(fn (array $attributes) => [
            'name' => "{$category}: " . $this->faker->words(2, true),
        ]);
    }
}
