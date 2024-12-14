<?php

namespace Database\Factories;

use App\Models\Content;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ContentFactory extends Factory
{
    protected $model = Content::class;

    public function definition(): array
    {
        $title = $this->faker->sentence();
        return [
            'title' => $title,
            'slug' => Str::slug($title),
            'excerpt' => $this->faker->paragraph(),
            'content' => $this->faker->paragraphs(5, true),
            'type' => $this->faker->randomElement(['post', 'page', 'news']),
            'template' => 'default',
            'author_id' => User::factory(),
            'status' => $this->faker->boolean(70),
            'published_at' => $this->faker->boolean(70) ? $this->faker->dateTimeBetween('-1 year') : null,
            'metadata' => [
                'views' => $this->faker->numberBetween(0, 10000),
                'reading_time' => $this->faker->numberBetween(1, 20),
            ],
            'seo_title' => $this->faker->sentence(),
            'seo_description' => $this->faker->paragraph(),
            'featured_image' => $this->faker->imageUrl(),
        ];
    }

    public function published(): self
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => true,
                'published_at' => $this->faker->dateTimeBetween('-1 year'),
            ];
        });
    }

    public function draft(): self
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => false,
                'published_at' => null,
            ];
        });
    }
}
