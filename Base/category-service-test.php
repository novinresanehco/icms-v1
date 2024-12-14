<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use App\Core\Models\Category;
use App\Core\Services\CategoryService;
use App\Core\Repositories\Contracts\CategoryRepositoryInterface;
use App\Core\Events\{CategoryCreated, CategoryUpdated, CategoryDeleted};
use App\Core\Exceptions\CategoryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

class CategoryServiceTest extends TestCase
{
    use RefreshDatabase;

    protected CategoryService $service;
    protected CategoryRepositoryInterface $repository;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->repository = $this->app->make(CategoryRepositoryInterface::class);
        $this->service = new CategoryService($this->repository);
        
        Event::fake();
    }

    public function test_can_create_category_with_meta(): void
    {
        $data = [
            'name' => 'Test Category',
            'slug' => 'test-category',
            'type' => 'default',
            'status' => 'active',
            'meta' => [
                ['key' => 'description', 'value' => 'Test Description']
            ]
        ];

        $category = $this->service->create($data);

        $this->assertInstanceOf(Category::class, $category);
        $this->assertEquals($data['name'], $category->name);
        $this->assertEquals($data['meta'][0]['value'], $category->meta->first()->value);
        Event::assertDispatched(CategoryCreated::class);
    }

    public function test_can_update_category(): void
    {
        $category = Category::factory()->create();
        $newData = [
            'name' => 'Updated Name',
            'meta' => [
                ['key' => 'new_meta', 'value' => 'New Value']
            ]
        ];

        $updated = $this->service->update($category->id, $newData);

        $this->assertEquals($newData['name'], $updated->name);
        $this->assertEquals($newData['meta'][0]['value'], $updated->meta->first()->value);
        Event::assertDispatched(CategoryUpdated::class);
    }

    public function test_can_delete_category(): void
    {
        $category = Category::factory()->create();

        $result = $this->service->delete($category->id);

        $this->assertTrue($result);
        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
        Event::assertDispatched(CategoryDeleted::class);
    }

    public function test_cannot_delete_nonexistent_category(): void
    {
        $this->expectException(CategoryException::class);
        $this->service->delete(999);
    }

    public function test_generates_unique_slug(): void
    {
        Category::factory()->create(['slug' => 'test-slug']);
        
        $data = [
            'name' => 'Test Category',
            'slug' => 'test-slug',
            'type' => 'default'
        ];

        $category = $this->service->create($data);

        $this->assertEquals('test-slug-1', $category->slug);
    }

    public function test_can_reorder_categories(): void
    {
        $categories = Category::factory()->count(3)->create();
        $order = $categories->pluck('id')->reverse()->toArray();

        $result = $this->service->reorder($order);

        $this->assertTrue($result);
        foreach ($order as $position => $id) {
            $this->assertEquals(
                $position, 
                Category::find($id)->order
            );
        }
    }
}
