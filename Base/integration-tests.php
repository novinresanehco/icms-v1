<?php

namespace Tests\Integration\Repositories;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Core\Repositories\PageRepository;
use App\Core\Repositories\Decorators\{
    MetricsAwareRepository,
    VersionedRepository,
    PermissionAwareRepository
};
use App\Models\Page;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Cache;

class RepositoryIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected $repository;
    protected $user;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create test user
        $this->user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($this->user);

        // Set up repository with all decorators
        $baseRepository = new PageRepository(new Page());
        
        $this->repository = new PermissionAwareRepository(
            new VersionedRepository(
                new MetricsAwareRepository(
                    $baseRepository,
                    app()->make('App\Core\Services\Metrics\MetricsCollector')
                ),
                app()->make('App\Core\Services\Version\VersionManager')
            ),
            app()->make('App\Core\Services\Permission\PermissionManager')
        );
    }

    /** @test */
    public function it_creates_content_with_version_and_permissions()
    {
        Event::fake();
        Cache::tags(['pages'])->flush();

        $attributes = [
            'title' => 'Test Page',
            'slug' => 'test-page',
            'content' => 'Test content',
            'status' => 'draft'
        ];

        $result = $this->repository->create($attributes);

        // Assert basic creation
        $this->assertInstanceOf(Page::class, $result);
        $this->assertEquals($attributes['title'], $result->title);
        
        // Assert version created
        $versions = $this->repository->getVersions($result->id);
        $this->assertCount(1, $versions);
        
        // Assert metrics recorded
        $metrics = app()->make('App\Core\Services\Metrics\MetricsCollector')
            ->getMetrics('repository.create');
        $this->assertNotEmpty($metrics);
        
        // Assert events dispatched
        Event::assertDispatched(\App\Core\Events\ContentCreated::class);
    }

    /** @test */
    public function it_handles_version_control_properly()
    {
        $page = Page::factory()->create();
        
        // Make multiple updates
        $updates = [
            ['title' => 'Version 1'],
            ['title' => 'Version 2'],
            ['title' => 'Version 3']
        ];

        foreach ($updates as $update) {
            $this->repository->update($page->id, $update);
        }

        // Check version history
        $versions = $this->repository->getVersions($page->id);
        $this->assertCount(4, $versions); // Including initial version
        
        // Revert to first version
        $firstVersion = collect($versions)->first();
        $reverted = $this->repository->revertToVersion($page->id, $firstVersion->id);
        
        $this->assertEquals($page->title, $reverted->title);
    }

    /** @test */
    public function it_enforces_permissions()
    {
        $this->expectException(\App\Core\Exceptions\AccessDeniedException::class);
        
        // Create restricted page
        $page = Page::factory()->create(['restricted' => true]);
        
        // Switch to non-admin user
        $regularUser = User::factory()->create(['role' => 'user']);
        $this->actingAs($regularUser);
        
        // Attempt to access restricted content
        $this->repository->find($page->id);
    }

    /** @test */
    public function it_handles_concurrent_updates_properly()
    {
        $page = Page::factory()->create();
        
        // Simulate concurrent updates
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        
        $this->actingAs($user1);
        $update1 = $this->repository->update($page->id, ['title' => 'Update 1']);
        
        $this->actingAs($user2);
        $update2 = $this->repository->update($page->id, ['title' => 'Update 2']);
        
        // Check version history for both updates
        $versions = $this->repository->getVersions($page->id);
        $this->assertCount(3, $versions); // Initial + 2 updates
        
        // Verify last update is current
        $page->refresh();
        $this->assertEquals('Update 2', $page->title);
    }
}
