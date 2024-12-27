<?php

namespace Tests\Unit\Core\Tagging;

use Tests\TestCase;
use App\Core\Tagging\TagManager;
use App\Core\Security\SecurityContext;

class TagManagerTest extends TestCase
{
    private TagManager $tagManager;
    private SecurityContext $context;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->tagManager = $this->app->make(TagManager::class);
        $this->context = $this->createSecurityContext();
    }

    public function test_creates_tag_with_valid_data(): void
    {
        $data = [
            'name' => 'Test Tag',
            'type' => 'category',
            'metadata' => ['key' => 'value']
        ];

        $tag = $this->tagManager->createTag($data, $this->context);

        $this->assertNotNull($tag);
        $this->assertEquals('Test Tag', $tag->name);
        $this->assertEquals('category', $tag->type);
    }

    public function test_attaches_tags_to_content(): void
    {
        $content = Content::factory()->create();
        $tags = Tag::factory()->count(3)->create();
        
        $this->tagManager->attachTags(
            $content->id,
            $tags->pluck('id')->toArray(),
            $this->context
        );

        $this->assertCount(3, $content->fresh()->tags);
    }

    public function test_retrieves_content_tags(): void
    {
        $content = Content::factory()->create();
        $tags = Tag::factory()->count(3)->create();
        
        $content->tags()->attach($tags);

        $contentTags = $this->tagManager->getContentTags($content->id);

        $this->assertCount(3, $contentTags);
    }

    private function createSecurityContext(): SecurityContext
    {
        return new SecurityContext(
            User::factory()->create(),
            'test-session',
            '127.0.0.1'
        );
    }
}
