<?php

namespace Tests\Unit\Repositories;

use Tests\TestCase;
use App\Models\Category;
use App\Repositories\CategoryRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

class CategoryRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private CategoryRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new CategoryRepository(new Category());
    }

    public function test_get_active_hierarchy()
    {
        $parent = Category::factory()->create([
            'status' => 'active',
            'parent_id' => null
        ]);

        Category::factory()->count(2)->create([
            'status' => 'active',
            'parent_id' => $parent->id
        ]);

        $result = $this->repository->getActiveHierarchy();

        $this->assertEquals(1, $result->count());
        $this->assertEquals(2, $result->first()->children->count());
    }

    public function test_get_active_by_slug()
    {
        $category = Category::factory()->create([
            'status' => 'active',
            'slug' => 'test-category'
        ]);

        $result = $this->repository->getActiveBySlug('test-category');

        $this->assertNotNull($result);
        $this->assertEquals($category->id, $result->id);
    }

    public function test_update_sort_order()
    {
        $categories = Category::factory()->count(3)->create();
        
        $sortData = [
            0 => $categories[2]->id,
            1 => $categories[0]->id,
            2 => $categories[1]->id
        ];

        $result = $this->repository->updateSortOrder($sortData);

        $this->assertTrue($result);
        $this->assertEquals(0, Category::find($categories[2]->id)->sort_order);
        $this->assertEquals(1, Category::find($categories[0]->id)->sort_order);
        $this->assertEquals(2, Category::find($categories[1]->id)->sort_order);
    }

    public function test_get_with_content_count()
    {
        $category = Category::factory()->create(['status' => 'active']);
        
        // Create related content
        $category->content()->createMany([
            ['status' => 'published', 'title' => 'Test 1'],
            ['status' => 'published', 'title' => 'Test 2'],
            ['status' => 'draft', 'title' => 'Test 3']
        ]);

        $result = $this->repository->getWithContentCount();

        $this->assertEquals(1, $result->count());
        $this->assertEquals(2, $result->first()->content_count);
    }

    public function test_move_category()
    {
        $parent = Category::factory()->create();
        $category = Category::factory()->create();

        $result = $this->repository->moveCategory($category->id, $parent->id);

        $this->assertTrue($result);
        $this->assertEquals($parent->id, $category->fresh()->parent_id);
    }

    public function test_prevent_moving_to_own_child()
    {
        $parent = Category::factory()->create();
        $child = Category::factory()->create(['parent_id' => $parent->id]);

        $result = $this->repository->moveCategory($parent->id, $child->id);

        $this->assertFalse($result);
        $this->assertNull($parent->fresh()->parent_id);
    }

    public function test_get_category_path()
    {
        $root = Category::factory()->create();
        $parent = Category::factory()->create(['parent_id' => $root->id]);
        $child = Category::factory()->create(['parent_id' => $parent->id]);

        $path = $this->repository->getCategoryPath($child->id);

        $this->assertEquals(3, $path->count());
        $this->assertEquals($root->id, $path->first()->id);
        $this->assertEquals($child->id, $path->last()->id);
    }

    public function test_update_category_status()
    {
        $parent = Category::factory()->create(['status' => 'active']);
        $children = Category::factory()->count(2)->create([
            'parent_id' => $parent->id,
            'status' => 'active'
        ]);

        $result = $this->repository->updateCategoryStatus($parent->id, 'inactive');

        $this->assertTrue($result);
        $this->assertEquals('inactive', $parent->fresh()->status);
        $this->assertEquals('inactive', Category::find($children[0]->id)->status);
        $this->assertEquals('inactive', Category::find($children[1]->id)->status);
    }
}
