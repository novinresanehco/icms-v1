<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Core\Models\{User, Category};
use Illuminate\Foundation\Testing\{RefreshDatabase, WithFaker};
use Laravel\Sanctum\Sanctum;

class CategoryControllerTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->user = User::factory()->create();
        $this->user->givePermissionTo([
            'view-categories',
            'create-categories',
            'edit-categories',
            'delete-categories',
            'reorder-categories'
        ]);
        
        Sanctum::actingAs($this->user);
    }

    public function test_can_list_categories(): void
    {
        Category::factory()->count(3)->create();

        $response = $this->getJson('/api/categories');

        $response->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'slug',
                        'type',
                        'status'
                    ]
                ],
                'meta',
                'links'
            ]);
    }

    public function test_can_create_category(): void
    {
        $data = [
            'name' => $this->faker->words(2, true),
            'type' => 'default',
            'status' => 'active',
            'meta' => [
                ['key' => 'description', 'value' => $this->faker->sentence]
            ]
        ];

        $response = $this->postJson('/api/categories', $data);

        $response->assertCreated()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'slug',
                    'type',
                    'status',
                    'meta'
                ]
            ]);

        $this->assertDatabaseHas('categories', [
            'name' => $data['name'],
            'type' => $data['type']
        ]);
    }

    public function test_can_show_category(): void
    {
        $category = Category::factory()->create();

        $response = $this->getJson("/api/categories/{$category->id}");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'slug',
                    'type',
                    'status'
                ]
            ])
            ->assertJsonPath('data.id', $category->id);
    }

    public function test_can_update_category(): void
    {
        $category = Category::factory()->create();
        $newData = ['name' => 'Updated Name'];

        $response = $this->patchJson("/api/categories/{$category->id}", $newData);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Updated Name');

        $this->assertDatabaseHas('categories', [
            'id' => $category->id,
            'name' => 'Updated Name'
        ]);
    }

    public function test_can_delete_category(): void
    {
        $category = Category::factory()->create();

        $response = $this->deleteJson("/api/categories/{$category->id}");

        $response->assertNoContent();
        $this->assertSoftDeleted('categories', ['id' => $category->id]);
    }

    public function test_cannot_delete_category_with_children(): void
    {
        $parent = Category::factory()->create();
        Category::factory()->create(['parent_id' => $parent->id]);

        $response = $this->deleteJson("/api/categories/{$parent->id}");

        $response->assertForbidden();
        $this->assertDatabaseHas('categories', ['id' => $parent->id]);
    }

    public function test_can_reorder_categories(): void
    {
        $categories = Category::factory()->count(3)->create();
        $order = $categories->pluck('id')->reverse()->values()->toArray();

        $response = $this->postJson('/api/categories/reorder', [
            'order' => $order
        ]);

        $response->assertOk()
            ->assertJsonStructure(['message']);

        foreach ($order as $position => $id) {
            $this->assertDatabaseHas('categories', [
                'id' => $id,
                'order' => $position
            ]);
        }
    }

    public function test_validates_required_fields(): void
    {
        $response = $this->postJson('/api/categories', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'type', 'status']);
    }

    public function test_prevents_duplicate_slugs(): void
    {
        $existing = Category::factory()->create(['slug' => 'test-slug']);
        
        $response = $this->postJson('/api/categories', [
            'name' => 'Test',
            'slug' => 'test-slug',
            'type' => 'default',
            'status' => 'active'
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('categories', ['slug' => 'test-slug-1']);
    }
}
