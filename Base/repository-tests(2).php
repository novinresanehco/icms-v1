<?php

namespace Tests\Unit\Repositories;

use Tests\TestCase;
use App\Core\Repositories\BaseRepository;
use App\Core\Repositories\Decorators\{
    CacheableRepository,
    EventAwareRepository,
    ValidatedRepository
};
use App\Models\Page;
use App\Core\Events\{ContentCreated, ContentUpdated, ContentDeleted};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Cache;

class BaseRepositoryTest extends TestCase
{
    use RefreshDatabase;

    protected $repository;
    protected $model;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->model = new Page();
        $this->repository = $this->getMockForAbstractClass(
            BaseRepository::class, 
            [$this->model]
        );
    }

    /** @test */
    public function it_can_create_a_model()
    {
        $attributes = [
            'title' => 'Test Page',
            'slug' => 'test-page',
            'content' => 'Test content'
        ];

        $result = $this->repository->create($attributes);

        $this->assertInstanceOf(Page::class, $result);
        $this->assertEquals($attributes['title'], $result->title);
        $this->assertDatabaseHas('pages', $attributes);
    }

    /** @test */
    public function it_can_find_a_model_by_id()
    {
        $page = Page::factory()->create();

        $result = $this->repository->find($page->id);

        $this->assertInstanceOf(Page::class, $result);
        $this->assertEquals($page->id, $result->id);
    }

    /** @test */
    public function it_throws_exception_when_model_not_found()
    {
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        $this->repository->find(999);
    }
}

class CacheableRepositoryTest extends TestCase
{
    use RefreshDatabase;

    protected $repository;
    protected $baseRepository;
    protected $model;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->model = new Page();
        $this->baseRepository = $this->getMockForAbstractClass(
            BaseRepository::class, 
            [$this->model]
        );
        $this->repository = new CacheableRepository($this->baseRepository);
    }

    /** @test */
    public function it_caches_find_results()
    {
        $page = Page::factory()->create();
        Cache::shouldReceive('remember')
            ->once()
            ->andReturn($page);

        $result = $this->repository->find($page->id);

        $this->assertEquals($page->id, $result->id);
    }

    /** @test */
    public function it_invalidates_cache_on_update()
    {
        $page = Page::factory()->create();
        Cache::shouldReceive('tags')->once()->andReturnSelf();
        Cache::shouldReceive('flush')->once();

        $this->repository->update($page->id, ['title' => 'Updated']);
    }
}

class EventAwareRepositoryTest extends TestCase
{
    use RefreshDatabase;

    protected $repository;
    protected $baseRepository;
    protected $model;

    protected function setUp(): void
    {
        parent::setUp();
        
        Event::fake();
        
        $this->model = new Page();
        $this->baseRepository = $this->getMockForAbstractClass(
            BaseRepository::class, 
            [$this->model]
        );
        $this->repository = new EventAwareRepository($this->baseRepository);
    }

    /** @test */
    public function it_dispatches_events_on_create()
    {
        $attributes = [
            'title' => 'Test Page',
            'slug' => 'test-page',
            'content' => 'Test content'
        ];

        $this->repository->create($attributes);

        Event::assertDispatched(ContentCreated::class);
    }

    /** @test */
    public function it_dispatches_events_on_update()
    {
        $page = Page::factory()->create();

        $this->repository->update($page->id, ['title' => 'Updated']);

        Event::assertDispatched(ContentUpdated::class);
    }
}

class ValidatedRepositoryTest extends TestCase
{
    use RefreshDatabase;

    protected $repository;
    protected $baseRepository;
    protected $validator;
    protected $model;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->model = new Page();
        $this->baseRepository = $this->getMockForAbstractClass(
            BaseRepository::class, 
            [$this->model]
        );
        $this->validator = $this->createMock(\App\Core\Validation\ValidatorInterface::class);
        $this->repository = new ValidatedRepository($this->baseRepository, $this->validator);
    }

    /** @test */
    public function it_validates_data_before_create()
    {
        $attributes = [
            'title' => 'Test Page',
            'slug' => 'test-page',
            'content' => 'Test content'
        ];

        $this->validator->expects($this->once())
            ->method('validate')
            ->with($attributes)
            ->willReturn(true);

        $this->repository->create($attributes);
    }

    /** @test */
    public function it_throws_exception_on_validation_failure()
    {
        $this->expectException(\App\Core\Exceptions\ValidationException::class);

        $attributes = ['title' => ''];

        $this->validator->expects($this->once())
            ->method('validate')
            ->with($attributes)
            ->willReturn(false);

        $this->repository->create($attributes);
    }
}
