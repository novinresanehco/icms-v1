<?php

namespace Tests\Unit\Repositories;

use Tests\TestCase;
use App\Core\Models\Category;
use App\Core\Repositories\CategoryRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class CategoryRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private CategoryRepository $repository;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new CategoryRepository(new Category());
    }

    public function test_can_create_category(): void
    {
        $data = [
            'name' => 'Test Category',
            'slug' => 'test-category',
            'type' => 'default',
            'status' => 'active'
        ];

        $category = $this->repository->create($data);

        $this->assertInstanceOf(Category::class, $category);
        $this->assertEquals($data['name'], $category->name);
        $this->assertEquals($data['slug'], $category->slug);
    }

    public function test_can_find_category_by_id(): void
    {
        $category = Category::factory()->create();

        $found = $this->repository->findById($category->id);

        $this->assertInstanceOf(Category::class, $found);
        $this->assertEquals($category->id, $found->id);
    }

    public function test_can_update_category(): void
    {
        $category = Category::factory()->create();
        $newData = ['name' => 'Updated Name'];

        $result = $this->repository->update($category, $newData);

        $this->assertTrue($result);
        $this->assertEquals('Updated Name', $category->fresh()->name);
    }

    public function test_cannot_delete_category_with_children(): void
    {
        $parent = Category::factory()->create();
        Category::factory()->create(['parent_id' => $parent->id]);

        $this->expectException(\RuntimeException::class);
        $this->repository->delete($parent->id);
    }

    public function test_can_get_root_categories(): void
    {
        Category::factory()->count(3)->create(['parent_id' => null]);
        
        $roots = $this->repository->getRoots();

        $this->assertEquals(3, $roots->count());
        $roots->each(fn($category) => $this->assertNull($category->parent_id));
    }

    public function test_can_get_categories_by_type(): void
    {
        Category::factory()->count(2)->create(['type' => 'blog']);
        Category::factory()->count(1)->create(['type' => 'product']);

        $blogCategories = $this->repository->getByType('blog');

        $this->assertEquals(2, $blogCategories->count());
        $blogCategories->each(fn($category) => $this->assertEquals('blog', $category->type));
    }

    public function test_can_reorder_categories(): void
    {
        $categories = Category::factory()->count(3)->create();
        $order = $categories->pluck('id')->reverse()->toArray();

        $result = $this->repository->reorder($order);

        $this->assertTrue($result);
        foreach ($order as $position => $id) {
            $this->assertEquals(
                $position, 
                Category::find($id)->order
            );
        }
    }
}
