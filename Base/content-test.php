<?php

namespace Tests\Unit;

use App\Models\Category;
use App\Models\Content;
use App\Models\Tag;
use App\Models\User;
use App\Repositories\ContentRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContentRepositoryTest extends TestCase
{
    use RefreshDatabase;

    protected ContentRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new ContentRepository(new Content());
    }

    public function test_can_create_content(): void
    {
        $user = User::factory()->create();
        $categories = Category::factory(2)->create();
        $tags = Tag::factory(2)->create();

        $data = [
            'title' => 'Test Content',
            'slug' => 'test-content',
            'content' => 'Test content body',
            'type' => 'post',
            'author_id' => $user->id,
            'categories' => $categories->pluck('id')->toArray(),
            'tags' => $tags->pluck('id')->toArray(),
            'editor_id' => $user->id,
        ];

        $contentId = $this->repository->create($data);

        $this->assertNotNull($contentId);
        $this->assertDatabaseHas('contents', [
            'id' => $contentId,
            'title' => 'Test Content'
        ]);
        $this->assertDatabaseHas('content_revisions', [
            'content_id' => $contentId
        ]);

        $content = Content::find($contentId);
        $this->assertCount(2, $content->categories);
        $this->assertCount(2, $content->tags);
    }

    public function test_can_update_content(): void
    {
        $content = Content::factory()
            ->has(Category::factory())
            ->has(Tag::factory())
            ->create();

        $newCategory = Category::factory()->create();
        $newTag = Tag::factory()->create();

        $data = [
            'title' => 'Updated Content',
            'content' => 'Updated content body',
            'categories' => [$newCategory->id],
            'tags' => [$newTag->id],
            'editor_id' => $content->author_id,
        ];

        $result = $this->repository->update($content->id, $data);

        $this->assertTrue($result);
        $this->assertDatabaseHas('contents', [
            'id' => $content->id,
            'title' => 'Updated Content'
        ]);
        
        $content->refresh();
        $this->assertCount(1, $content->categories);
        $this->assertCount(1, $content->tags);
        $this->assertEquals($newCategory->id, $content->categories->first()->id);
        $this->assertEquals($newTag->id, $content->tags->first()->id);
    }

    public function test_can_publish_content(): void
    {
        $content = Content::factory()->create(['status' => false]);

        $result = $this->repository->publishContent($content->id);

        $this->assertTrue($result);
        $this->assertDatabaseHas('contents', [
            'id' => $content->id,
            'status' => true,
        ]);
        $this->assertNotNull(Content::find($content->id)->published_at);
    }

    public function test_can_search_content(): void
    {
        Content::factory()->create([
            'title' => 'Searchable Title',
            'content' => 'Regular content'
        ]);
        Content::factory()->create([
            'title' => 'Regular Title',
            'content' => 'Searchable content'
        ]);
        Content::factory()->create([
            'title' => 'Other Title',
            'content' => 'Other content'
        ]);

        $results = $this->repository->search('Searchable');

        $this->assertEquals(2, $results->total());
    }
}
