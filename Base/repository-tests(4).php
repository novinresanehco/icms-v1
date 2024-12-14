<?php

namespace Tests\Unit\Repositories;

use App\Core\Repositories\Contracts\PageRepositoryInterface;
use App\Core\Repositories\Factories\RepositoryFactory;
use App\Models\Page;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PageRepositoryTest extends TestCase
{
    use RefreshDatabase;

    protected RepositoryFactory $factory;
    protected PageRepositoryInterface $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->factory = new RepositoryFactory();
    }

    public function test_can_create_page_with_valid_data()
    {
        $page = Page::factory()->make();
        $this->repository = $this->factory->createWithModel(
            PageRepositoryInterface::class,
            $page
        );

        $created = $this->repository->create([
            'title' => 'Test Page',
            'slug' => 'test-page',
            'content' => 'Test content',
            'template' => 'default',
            'status' => 'draft'
        ]);

        $this->assertInstanceOf(Page::class, $created);
        $this->assertEquals('Test Page', $created->title);
    }

    public function test_cannot_create_page_with_invalid_data()
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $page = Page::factory()->make();
        $this->repository = $this->factory->createWithModel(
            PageRepositoryInterface::class,
            $page
        );

        $this->repository->create([
            'title' => '', // Invalid: required field
            'slug' => 'test-page'
        ]);
    }

    public function test_can_update_page_with_valid_data()
    {
        $page = Page::factory()->create();
        $this->repository = $this->factory->createWithModel(
            PageRepositoryInterface::class,
            $page
        );

        $updated = $this->repository->update($page->id, [
            'title' => 'Updated Title',
            'content' => 'Updated content'
        ]);

        $this->assertEquals('Updated Title', $updated->title);
        $this->assertEquals('Updated content', $updated->content);
    }

    public function test_cannot_create_duplicate_slug()
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $existingPage = Page::factory()->create(['slug' => 'existing-slug']);
        $this->repository = $this->factory->createWithModel(
            PageRepositoryInterface::class,
            $existingPage
        );

        $this->repository->create([
            'title' => 'New Page',
            'slug' => 'existing-slug', // Should fail validation
            'content' => 'Content',
            'template' => 'default',
            'status' => 'draft'
        ]);
    }
}
