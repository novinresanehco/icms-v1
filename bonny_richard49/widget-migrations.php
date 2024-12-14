// database/migrations/2024_01_01_000001_create_widgets_table.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateWidgetsTable extends Migration
{
    public function up(): void
    {
        Schema::create('widgets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('identifier')->unique();
            $table->string('type', 50);
            $table->string('area', 50);
            $table->json('settings')->nullable();
            $table->integer('order')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('cache_ttl')->nullable();
            $table->json('visibility_rules')->nullable();
            $table->json('permissions')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['area', 'order']);
            $table->index('type');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('widgets');
    }
}

// database/factories/WidgetFactory.php
<?php

namespace Database\Factories;

use App\Core\Widget\Models\Widget;
use Illuminate\Database\Eloquent\Factories\Factory;

class WidgetFactory extends Factory
{
    protected $model = Widget::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->words(3, true),
            'identifier' => $this->faker->unique()->slug(2),
            'type' => $this->faker->randomElement(['content', 'menu', 'social', 'custom']),
            'area' => $this->faker->randomElement(['sidebar', 'footer', 'header']),
            'settings' => [
                'display' => $this->faker->randomElement(['list', 'grid']),
                'items_per_page' => $this->faker->numberBetween(5, 20)
            ],
            'order' => $this->faker->unique()->numberBetween(1, 100),
            'is_active' => $this->faker->boolean(80),
            'cache_ttl' => $this->faker->optional()->numberBetween(300, 3600),
            'visibility_rules' => $this->faker->optional()->randomElement([
                [
                    'conditions' => [
                        ['type' => 'role', 'value' => 'admin'],
                        ['type' => 'permission', 'value' => 'view_dashboard']
                    ],
                    'operator' => 'and'
                ]
            ]),
            'permissions' => $this->faker->optional()->randomElement([
                [
                    ['type' => 'role', 'value' => 'user'],
                    ['type' => 'permission', 'value' => 'access_widgets']
                ]
            ]),
            'metadata' => [
                'version' => '1.0.0',
                'author' => $this->faker->name,
                'created_at' => now()->toIso8601String()
            ]
        ];
    }

    public function inactive(): self
    {
        return $this->state(function (array $attributes) {
            return [
                'is_active' => false
            ];
        });
    }

    public function sidebar(): self
    {
        return $this->state(function (array $attributes) {
            return [
                'area' => 'sidebar'
            ];
        });
    }

    public function content(): self
    {
        return $this->state(function (array $attributes) {
            return [
                'type' => 'content'
            ];
        });
    }
}