<?php

namespace Tests\Unit\Tag;

use Tests\TestCase;
use App\Core\Tag\Models\Tag;
use App\Core\Tag\Services\TagService;
use App\Core\Tag\Events\{TagCreated, TagUpdated, TagsAttached};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use App\Core\Tag\Exceptions\TagValidationException;

class TagTest extends TestCase
{
    use RefreshDatabase;

    protected TagService $tagService;

    public function setUp(): void
    {
        parent::setUp();
        
        $this->tagService = app(TagService::class);
    }

    /** @test */
    public function it_can_create_a_tag(): void
    {
        Event::fake();

        $tagData = [
            'name' => 'Test Tag',
            'description' => 'Test Description'
        ];

        $tag = $this->tagService->create($tagData);

        $this->assertDatabaseHas('tags', [
            'id' => $tag->id,
            'name' => 'Test Tag'
        ]);

        Event::assertDispatched(TagCreated::class);
    }

    /** @test */
    public function it_validates_tag_name_uniqueness(): void
    {
        $this->expectException(TagValidationException::class);

        // Create first tag
        $this->tagService->create([
            'name' => 'Unique Tag'
        ]);

        // Try to create another tag with the same name
        $this->tagService->create([
            'name' => 'Unique Tag'
        ]);
    }

    /** @test */
    public function it_can_update_a_tag(): void
    {
        Event::fake();

        $tag = $this->tagService->create([
            'name' => 'Original Name'
        ]);

        $updatedTag = $this->tagService->update($tag->id, [
            'name' => 'Updated Name'
        ]);

        $this->assertEquals('Updated Name', $updatedTag->name);
        Event::assertDispatched(TagUpdated::class);
    }

    /** @test */
    public function it_can_attach_tags_to_content(): void
    {
        Event::fake();

        $content = Content::factory()->create();
        $tags = Tag::factory()->count(3)->create();

        $this->tagService->attachToContent(
            $content->id,
            $tags->pluck('id')->toArray()
        );

        $this->assertCount(3, $content->fresh()->tags);
        Event::assertDispatched(TagsAttached::class);
    }

    /** @test */
    public function it_can_merge_tags(): void
    {
        $sourceTag = $this->tagService->create([
            'name' => 'Source Tag'
        ]);

        $targetTag = $this->tagService->create([
            'name' => 'Target Tag'
        ]);

        // Attach source tag to some content
        $content = Content::factory()->create();
        $this->tagService->attachToContent($content->id, [$sourceTag->id]);

        // Merge tags
        $this->tagService->mergeTags($sourceTag->id, $targetTag->id);

        // Assert source tag is deleted
        $this->assertDatabaseMissing('tags', ['id' => $sourceTag->id]);

        // Assert content is now tagged with target tag
        $this->assertTrue($content->fresh()->tags->contains($targetTag));
    }

    /** @test */
    public function it_handles_popular_tags(): void
    {
        // Create tags with different usage counts
        $popularTag = Tag::factory()->create();
        $lessPopularTag = Tag::factory()->create();

        // Attach popular tag to more content
        Content::factory()->count(5)->create()->each(function ($content) use ($popularTag) {
            $this->tagService->attachToContent($content->id, [$popularTag->id]);
        });

        Content::factory()->count(2)->create()->each(function ($content) use ($lessPopularTag) {
            $this->tagService->attachToContent($content->id, [$lessPopularTag->id]);
        });

        $popularTags = $this->tagService->getPopularTags(1);

        $this->assertCount(1, $popularTags);
        $this->assertEquals($popularTag->id, $popularTags->first()->id);
    }
}
