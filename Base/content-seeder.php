<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Content;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Seeder;

class ContentSeeder extends Seeder
{
    public function run(): void
    {
        // Create categories
        $categories = Category::factory(5)->create();

        // Create tags
        $tags = Tag::factory(10)->create();

        // Create users
        $users = User::factory(3)->create();

        // Create content
        Content::factory(50)->create()->each(function ($content) use ($categories, $tags) {
            // Attach random categories
            $content->categories()->attach(
                $categories->random(rand(1, 3))->pluck('id')->toArray()
            );

            // Attach random tags
            $content->tags()->attach(
                $tags->random(rand(2, 5))->pluck('id')->toArray()
            );

            // Create revisions
            if (rand(0, 1)) {
                $content->revisions()->create([
                    'title' => $content->title,
                    'content' => $content->content,
                    'metadata' => $content->metadata,
                    'editor_id' => User::inRandomOrder()->first()->id,
                    'reason' => 'Initial revision',
                ]);
            }
        });

        // Create some nested categories
        $parentCategories = $categories->random(2);
        foreach ($parentCategories as $parent) {
            Category::factory(2)->create([
                'parent_id' => $parent->id
            ]);
        }
    }
}
