<?php

namespace Tests\Unit\Repositories\Events;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Events\Repository\{EntityCreated, EntityUpdated, EntityDeleted};
use App\Repositories\{ContentRepository, CategoryRepository};
use App\Core\Database\Performance\DatabasePerformanceManager;
use App\Subscribers\RepositoryEventSubscriber;
use Illuminate\Support\Facades\{Event, Cache, Log};
use Mockery;

class RepositoryEventTest extends TestCase
{
    use RefreshDatabase;

    protected $performanceManager;
    protected $subscriber;
    protected $contentRepository;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->performanceManager = Mockery::mock(DatabasePerformanceManager::class);
        $this->performanceManager->shouldReceive('monitorQueryPerformance')->byDefault();
        
        $this->subscriber = new RepositoryEventSubscriber();
        $this->contentRepository = new ContentRepository($this->performanceManager);
        
        // Clear cache before each test
        Cache::tags(['content', 'category', 'tag'])->flush();
    }

    /** @test */
    public function it_fires_created_event_when_entity_is_created()
    {
        Event::fake([EntityCreated::class]);

        $content = $this->contentRepository->create([
            'title' => 'Test Content',
            'content' => 'Testing events'
        ]);

        Event::assertDispatched(EntityCreated::class, function ($event) use ($content) {
            return $event->getEntity()->id === $content->id;
        });
    }

    /** @test */
    public function it_properly_handles_cache_invalidation_on_entity_creation()
    {
        // First, cache some data
        $this->contentRepository->all();
        $cacheKey = $this->contentRepository->getCacheKey('all');
        
        $this->assertTrue(Cache::tags(['content'])->has($cacheKey));

        // Create new content, which should invalidate cache
        $this->contentRepository->create([
            'title' => 'Cache Test',
            'content' => 'Testing cache invalidation'
        ]);

        $this->assertFalse(Cache::tags(['content'])->has($cacheKey));
    }

    /** @test */
    public function it_logs_repository_actions()
    {
        Log::shouldReceive('info')
            ->once()
            ->withArgs(function ($message, $context) {
                return $message === 'Repository entity created' &&
                    isset($context['entity']) &&
                    isset($context['id']);
            });

        $content = $this->contentRepository->create([
            'title' => 'Log Test',
            'content' => 'Testing logging'
        ]);
    }

    /** @test */
    public function it_handles_related_cache_invalidation()
    {
        $categoryRepo = new CategoryRepository($this->performanceManager);

        // Cache category data
        $categoryRepo->all();
        $categoryCacheKey = $categoryRepo->getCacheKey('all');

        // Create content with category, should invalidate both caches
        $category = $categoryRepo->create(['name' => 'Test Category']);
        $content = $this->contentRepository->create([
            'title' => 'Related Content',
            'content' => 'Testing related caches',
            'category_id' => $category->id
        ]);

        $this->assertFalse(Cache::tags(['category'])->has($categoryCacheKey));
    }

    /** @test */
    public function it_handles_bulk_operations_correctly()
    {
        Event::fake([EntityCreated::class]);

        $this->contentRepository->beginTransaction();

        $contents = [];
        for ($i = 0; $i < 3; $i++) {
            $contents[] = $this->contentRepository->create([
                'title' => "Bulk Content {$i}",
                'content' => "Testing bulk operations {$i}"
            ]);
        }

        $this->contentRepository->commit();

        Event::assertDispatchedTimes(EntityCreated::class, 3);
    }

    /** @test */
    public function it_handles_failed_transactions_correctly()
    {
        Event::fake([EntityCreated::class]);
        
        try {
            $this->contentRepository->beginTransaction();

            $this->contentRepository->create([
                'title' => 'Transaction Test',
                'content' => 'Testing transactions'
            ]);

            throw new \Exception('Forced transaction failure');

            $this->contentRepository->commit();
        } catch (\Exception $e) {
            $this->contentRepository->rollBack();
        }

        Event::assertNotDispatched(EntityCreated::class);
    }
}
