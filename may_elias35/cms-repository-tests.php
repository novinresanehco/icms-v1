```php
namespace Tests\Unit\Repository;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Core\Repository\{ContentRepository, TagRepository, MediaRepository};
use App\Core\Cache\CacheManager;
use App\Models\{Content, Tag, Media};
use App\Core\Criteria\{PublishedCriteria, TaggedWithCriteria, SearchCriteria};
use App\Core\Exceptions\RepositoryException;

class BaseRepositoryTest extends TestCase
{
    use RefreshDatabase;

    protected $repository;
    protected $cacheManager;
    protected $model;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->cacheManager = $this->mock(CacheManager::class);
        $this->model = new Content();
        $this->repository = new ContentRepository($this->model, $this->cacheManager);
    }

    /** @test */
    public function it_can_find_by_id()
    {
        // Arrange
        $content = Content::factory()->create();
        
        $this->cacheManager
            ->shouldReceive('remember')
            ->once()
            ->andReturn($content);

        // Act
        $result = $this->repository->find($content->id);

        // Assert
        $this->assertInstanceOf(Content::class, $result);
        $this->assertEquals($content->id, $result->id);
    }

    /** @test */
    public function it_throws_exception_when_model_not_found()
    {
        $this->expectException(RepositoryException::class);
        $this->repository->findOrFail(999);
    }

    /** @test */
    public function it_can_create_new_record()
    {
        // Arrange
        $data = Content::factory()->raw();

        // Act
        $result = $this->repository->create($data);

        // Assert
        $this->assertInstanceOf(Content::class, $result);
        $this->assertDatabaseHas('contents', ['id' => $result->id]);
    }
}

class ContentRepositoryTest extends TestCase
{
    use RefreshDatabase;

    protected ContentRepository $repository;
    protected CacheManager $cacheManager;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->cacheManager = $this->mock(CacheManager::class);
        $this->repository = new ContentRepository(new Content(), $this->cacheManager);
    }

    /** @test */
    public function it_can_find_published_content()
    {
        // Arrange
        $published = Content::factory()->count(3)->create(['status' => 'published']);
        $draft = Content::factory()->create(['status' => 'draft']);

        $this->cacheManager
            ->shouldReceive('remember')
            ->once()
            ->andReturn($published);

        // Act
        $result = $this->repository->findPublished();

        // Assert
        $this->assertCount(3, $result);
        $this->assertNotContains($draft->id, $result->pluck('id'));
    }

    /** @test */
    public function it_can_search_content_with_criteria()
    {
        // Arrange
        $content = Content::factory()->create([
            'title' => 'Test Title',
            'content' => 'Test Content'
        ]);

        // Act
        $result = $this->repository->findWithCriteria([
            new SearchCriteria(['search' => 'Test']),
            new PublishedCriteria()
        ]);

        // Assert
        $this->assertCount(1, $result);
        $this->assertEquals($content->id, $result->first()->id);
    }

    /** @test */
    public function it_can_update_content_status()
    {
        // Arrange
        $content = Content::factory()->create(['status' => 'draft']);

        // Act
        $result = $this->repository->updateStatus($content->id, 'published');

        // Assert
        $this->assertEquals('published', $result->status);
        $this->assertDatabaseHas('contents', [
            'id' => $content->id,
            'status' => 'published'
        ]);
    }
}

class TagRepositoryTest extends TestCase
{
    use RefreshDatabase;

    protected TagRepository $repository;
    protected CacheManager $cacheManager;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->cacheManager = $this->mock(CacheManager::class);
        $this->repository = new TagRepository(new Tag(), $this->cacheManager);
    }

    /** @test */
    public function it_can_find_tag_by_name()
    {
        // Arrange
        $tag = Tag::factory()->create(['name' => 'TestTag']);

        $this->cacheManager
            ->shouldReceive('remember')
            ->once()
            ->andReturn($tag);

        // Act
        $result = $this->repository->findByName('TestTag');

        // Assert
        $this->assertInstanceOf(Tag::class, $result);
        $this->assertEquals('TestTag', $result->name);
    }

    /** @test */
    public function it_can_get_popular_tags()
    {
        // Arrange
        $tags = Tag::factory()->count(5)->create();
        $content = Content::factory()->count(3)->create();
        
        $tags[0]->contents()->attach($content->pluck('id'));

        $this->cacheManager
            ->shouldReceive('remember')
            ->once()
            ->andReturn(collect([$tags[0]]));

        // Act
        $result = $this->repository->getPopularTags(1);

        // Assert
        $this->assertCount(1, $result);
        $this->assertEquals($tags[0]->id, $result->first()->id);
    }

    /** @test */
    public function it_can_attach_tags_to_content()
    {
        // Arrange
        $content = Content::factory()->create();
        $tags = Tag::factory()->count(3)->create();

        // Act
        $this->repository->attachToContent($content->id, $tags->pluck('id')->toArray());

        // Assert
        $this->assertCount(3, $content->fresh()->tags);
    }
}

class MediaRepositoryTest extends TestCase
{
    use RefreshDatabase;

    protected MediaRepository $repository;
    protected CacheManager $cacheManager;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->cacheManager = $this->mock(CacheManager::class);
        $this->repository = new MediaRepository(new Media(), $this->cacheManager);
    }

    /** @test */
    public function it_can_find_media_by_type()
    {
        // Arrange
        $images = Media::factory()->count(3)->create(['type' => 'image']);
        $documents = Media::factory()->create(['type' => 'document']);

        $this->cacheManager
            ->shouldReceive('remember')
            ->once()
            ->andReturn(collect($images));

        // Act
        $result = $this->repository->findByType('image');

        // Assert
        $this->assertCount(3, $result);
        $this->assertNotContains($documents->id, $result->pluck('id'));
    }

    /** @test */
    public function it_can_update_media_metadata()
    {
        // Arrange
        $media = Media::factory()->create();
        $metadata = ['alt' => 'Test Alt Text'];

        // Act
        $result = $this->repository->updateMetadata($media->id, $metadata);

        // Assert
        $this->assertEquals($metadata['alt'], $result->metadata['alt']);
        $this->assertDatabaseHas('media', [
            'id' => $media->id,
            'metadata' => json_encode($metadata)
        ]);
    }

    /** @test */
    public function it_can_attach_media_to_content()
    {
        // Arrange
        $content = Content::factory()->create();
        $media = Media::factory()->count(3)->create();

        // Act
        $this->repository->attachToContent($content->id, $media->pluck('id')->toArray());

        // Assert
        $this->assertCount(3, $content->fresh()->media);
    }
}
```

This implementation provides:

1. Base Repository Tests:
   - Common CRUD operation testing
   - Cache integration testing
   - Exception handling testing

2. Content Repository Tests:
   - Published content filtering
   - Search functionality
   - Status updates
   - Criteria pattern testing

3. Tag Repository Tests:
   - Name-based finding
   - Popular tags retrieval
   - Content attachment

4. Media Repository Tests:
   - Type-based finding
   - Metadata updates
   - Content attachment

Would you like me to continue with:
1. Feature tests for the repositories
2. Integration tests with services
3. Performance testing scenarios
4. Cache testing scenarios
5. Additional repository tests

Please let me know which aspect you'd like to focus on next.