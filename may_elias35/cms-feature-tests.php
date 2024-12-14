```php
namespace Tests\Feature\Repository;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Core\Repository\{ContentRepository, TagRepository, MediaRepository};
use App\Core\Services\{ContentService, TagService, MediaService};
use App\Models\{Content, Tag, Media, User};
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class ContentRepositoryFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected ContentService $contentService;
    protected ContentRepository $repository;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->repository = app(ContentRepository::class);
        $this->contentService = app(ContentService::class);
        $this->user = User::factory()->create(['role' => 'editor']);
        
        $this->actingAs($this->user);
    }

    /** @test */
    public function it_can_create_content_with_relationships()
    {
        // Arrange
        $tags = Tag::factory()->count(3)->create();
        $media = Media::factory()->count(2)->create();

        $data = [
            'title' => 'Test Content',
            'slug' => 'test-content',
            'content' => 'Test content body',
            'status' => 'draft',
            'tags' => $tags->pluck('id')->toArray(),
            'media' => $media->pluck('id')->toArray()
        ];

        // Act
        $content = $this->contentService->createContent($data);

        // Assert
        $this->assertDatabaseHas('contents', [
            'id' => $content->id,
            'title' => 'Test Content'
        ]);

        $this->assertCount(3, $content->tags);
        $this->assertCount(2, $content->media);
    }

    /** @test */
    public function it_can_handle_complex_content_workflow()
    {
        // Arrange
        $content = Content::factory()
            ->has(Tag::factory()->count(2))
            ->has(Media::factory()->count(1))
            ->create(['status' => 'draft']);

        // Act & Assert - Publishing
        $publishedContent = $this->contentService->publishContent($content->id);
        $this->assertEquals('published', $publishedContent->status);

        // Act & Assert - Updating with new relationships
        $newTags = Tag::factory()->count(2)->create();
        $updatedContent = $this->contentService->updateContent($content->id, [
            'title' => 'Updated Title',
            'tags' => $newTags->pluck('id')->toArray()
        ]);

        $this->assertEquals('Updated Title', $updatedContent->title);
        $this->assertCount(2, $updatedContent->fresh()->tags);
    }

    /** @test */
    public function it_can_handle_content_versioning()
    {
        // Arrange
        $content = Content::factory()->create();

        // Act - Create multiple versions
        for ($i = 1; $i <= 3; $i++) {
            $this->contentService->updateContent($content->id, [
                'title' => "Version {$i}",
                'content' => "Content version {$i}"
            ]);
        }

        // Assert
        $versions = $content->versions()->orderBy('created_at', 'desc')->get();
        $this->assertCount(3, $versions);
        $this->assertEquals("Version 3", $versions->first()->title);
    }
}

class MediaRepositoryFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected MediaService $mediaService;
    protected MediaRepository $repository;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        
        Storage::fake('public');
        
        $this->repository = app(MediaRepository::class);
        $this->mediaService = app(MediaService::class);
        $this->user = User::factory()->create(['role' => 'editor']);
        
        $this->actingAs($this->user);
    }

    /** @test */
    public function it_can_handle_media_upload_and_processing()
    {
        // Arrange
        $file = UploadedFile::fake()->image('test.jpg');

        // Act
        $media = $this->mediaService->uploadMedia($file, [
            'alt_text' => 'Test image',
            'caption' => 'Test caption'
        ]);

        // Assert
        Storage::disk('public')->assertExists($media->path);
        $this->assertDatabaseHas('media', [
            'id' => $media->id,
            'type' => 'image/jpeg'
        ]);
    }

    /** @test */
    public function it_can_handle_media_optimization()
    {
        // Arrange
        $file = UploadedFile::fake()->image('test.jpg', 2000, 2000);

        // Act
        $media = $this->mediaService->uploadMedia($file);

        // Assert
        $this->assertLessThan(2000, getimagesize(Storage::path($media->path))[0]);
        $this->assertTrue($media->optimized);
    }

    /** @test */
    public function it_can_manage_media_collections()
    {
        // Arrange
        $content = Content::factory()->create();
        $files = [
            UploadedFile::fake()->image('test1.jpg'),
            UploadedFile::fake()->image('test2.jpg')
        ];

        // Act
        $mediaItems = collect($files)->map(fn($file) => 
            $this->mediaService->uploadMedia($file)
        );

        $this->mediaService->attachToContent(
            $content->id, 
            $mediaItems->pluck('id')->toArray()
        );

        // Assert
        $this->assertCount(2, $content->fresh()->media);
    }
}

class TagRepositoryFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected TagService $tagService;
    protected TagRepository $repository;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->repository = app(TagRepository::class);
        $this->tagService = app(TagService::class);
        $this->user = User::factory()->create(['role' => 'editor']);
        
        $this->actingAs($this->user);
    }

    /** @test */
    public function it_can_manage_tag_hierarchy()
    {
        // Arrange
        $parentTag = Tag::factory()->create();
        $childTags = Tag::factory()->count(3)->create([
            'parent_id' => $parentTag->id
        ]);

        // Act
        $result = $this->repository->getTagHierarchy();

        // Assert
        $this->assertCount(1, $result);
        $this->assertCount(3, $result->first()->children);
    }

    /** @test */
    public function it_can_handle_tag_merging()
    {
        // Arrange
        $sourceTag = Tag::factory()->create();
        $targetTag = Tag::factory()->create();
        $content = Content::factory()->create();
        $content->tags()->attach($sourceTag->id);

        // Act
        $this->tagService->mergeTags($sourceTag->id, $targetTag->id);

        // Assert
        $this->assertDatabaseMissing('tags', ['id' => $sourceTag->id]);
        $this->assertTrue($content->fresh()->tags->contains($targetTag));
    }

    /** @test */
    public function it_can_handle_tag_usage_analytics()
    {
        // Arrange
        $tag = Tag::factory()->create();
        $contents = Content::factory()->count(5)->create();
        $contents->each(fn($content) => $content->tags()->attach($tag->id));

        // Act
        $analytics = $this->tagService->getTagAnalytics($tag->id);

        // Assert
        $this->assertEquals(5, $analytics['usage_count']);
        $this->assertArrayHasKey('trending_score', $analytics);
    }
}

class RepositoryIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected ContentService $contentService;
    protected TagService $tagService;
    protected MediaService $mediaService;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->contentService = app(ContentService::class);
        $this->tagService = app(TagService::class);
        $this->mediaService = app(MediaService::class);
    }

    /** @test */
    public function it_can_handle_complex_content_creation_workflow()
    {
        // Arrange
        $tags = Tag::factory()->count(3)->create();
        $file = UploadedFile::fake()->image('test.jpg');

        // Act
        $media = $this->mediaService->uploadMedia($file);
        
        $content = $this->contentService->createContent([
            'title' => 'Test Content',
            'content' => 'Test content body',
            'status' => 'draft',
            'tags' => $tags->pluck('id')->toArray(),
            'media' => [$media->id]
        ]);

        $this->contentService->publishContent($content->id);

        // Assert
        $publishedContent = Content::with(['tags', 'media'])->find($content->id);
        
        $this->assertEquals('published', $publishedContent->status);
        $this->assertCount(3, $publishedContent->tags);
        $this->assertCount(1, $publishedContent->media);
    }
}
```

This implementation provides:

1. Content Repository Feature Tests:
   - Complex relationship testing
   - Workflow testing
   - Versioning testing

2. Media Repository Feature Tests:
   - File upload handling
   - Image optimization
   - Collection management

3. Tag Repository Feature Tests:
   - Hierarchy management
   - Tag merging
   - Analytics testing

4. Integration Tests:
   - Cross-service workflows
   - Complex operations
   - State verification

Would you like me to continue with:
1. Performance testing scenarios
2. Security testing implementation
3. Cache integration testing
4. API integration testing
5. Additional feature tests

Please let me know which aspect you'd like to focus on next.