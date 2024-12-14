<?php

namespace Tests\Unit\Repositories;

use Tests\TestCase;
use App\Core\Cache\RepositoryCacheConfig;
use Illuminate\Support\Facades\Cache;
use Illuminate\Foundation\Testing\RefreshDatabase;

class RepositoryCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_caches_repository_queries()
    {
        $repository = app(ContentRepository::class);
        $cacheKey = $repository->getCacheKey('find', [1]);

        Cache::tags($repository->getCacheTags())->forget($cacheKey);

        $content = Content::factory()->create();
        
        // First call should hit database
        $result1 = $repository->find($content->id);
        
        // Second call should hit cache
        $result2 = $repository->find($content->id);

        $this->assertTrue(Cache::tags($repository->getCacheTags())->has($cacheKey));
        $this->assertEquals($result1->toArray(), $result2->toArray());
    }

    public function test_it_clears_cache_on_model_changes()
    {
        $repository = app(ContentRepository::class);
        $content = Content::factory()->create();
        $cacheKey = $repository->getCacheKey('find', [$content->id]);

        // Cache the find query
        $repository->find($content->id);
        
        // Update should clear cache
        $repository->update($content->id, ['title' => 'Updated']);

        $this->assertFalse(Cache::tags($repository->getCacheTags())->has($cacheKey));
    }

    public function test_it_handles_cache_tags_correctly()
    {
        $repository = app(ContentRepository::class);
        $expectedTags = array_merge(['contents'], RepositoryCacheConfig::getTags('content'));

        $this->assertEquals($expectedTags, $repository->getCacheTags());
    }
}
