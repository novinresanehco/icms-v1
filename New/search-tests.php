<?php

namespace Tests\Unit\Search;

use Tests\TestCase;
use App\Core\Search\{OptimizedSearchService, SearchIndex};
use App\Core\Repository\SearchRepository;
use App\Core\Security\SecurityManager;
use App\Core\Validation\ValidationService;
use App\Core\Exceptions\{UnauthorizedException, ValidationException};
use Illuminate\Foundation\Testing\RefreshDatabase;

class SearchServiceTest extends TestCase
{
    use RefreshDatabase;

    private OptimizedSearchService $searchService;
    private SearchRepository $repository;
    private SecurityManager $security;
    private ValidationService $validator;
    private SearchIndex $searchIndex;

    protected function setUp(): void
    {
        parent::setUp();

        $this->security = $this->mock(SecurityManager::class);
        $this->repository = $this->mock(SearchRepository::class);
        $this->validator = $this->app->make(ValidationService::class);
        $this->searchIndex = $this->mock(SearchIndex::class);

        $this->searchService = new OptimizedSearchService(
            $this->security,
            $this->repository,
            $this->validator,
            $this->searchIndex
        );
    }

    public function test_throws_exception_for_empty_query(): void
    {
        $this->expectException(ValidationException::class);
        $this->searchService->search('');
    }

    public function test_throws_exception_when_unauthorized(): void
    {
        $this->security->shouldReceive('hasPermission')
            ->with('search.execute')
            ->once()
            ->andReturn(false);

        $this->expectException(UnauthorizedException::class);
        $this->searchService->search('test query');
    }

    public function test_returns_filtered_results(): void
    {
        $this->security->shouldReceive('hasPermission')
            ->with('search.execute')
            ->once()
            ->andReturn(true);

        $this->security->shouldReceive('hasPermission')
            ->with('view.article')
            ->times(2)
            ->andReturn(true);

        $this->searchIndex->shouldReceive('search')
            ->once()
            ->andReturn(['1', '2']);

        $this->repository->shouldReceive('findByIds')
            ->with(['1', '2'])
            ->once()
            ->andReturn(collect([
                (object)['id' => '1', 'type' => 'article'],
                (object)['id' => '2', 'type' => 'article']
            ]));

        $results = $this->searchService->search('test query');
        
        $this->assertCount(2, $results);
    }

    public function test_indexes_content_successfully(): void
    {
        $this->security->shouldReceive('hasPermission')
            ->with('content.index')
            ->once()
            ->andReturn(true);

        $this->repository->shouldReceive('store')
            ->once()
            ->andReturn((object)['id' => '1']);

        $this->searchIndex->shouldReceive('add')
            ->once();

        $this->searchService->index('1', 'test content');
    }

    public function test_throws_exception_when_unauthorized_to_index(): void
    {
        $this->security->shouldReceive('hasPermission')
            ->with('content.index')
            ->once()
            ->andReturn(false);

        $this->expectException(UnauthorizedException::class);
        $this->searchService->index('1', 'test content');
    }
}

class SearchIndexTest extends TestCase
{
    use RefreshDatabase;

    private SearchIndex $searchIndex;

    protected function setUp(): void
    {
        parent::setUp();
        $this->searchIndex = new SearchIndex();
    }

    public function test_adds_document_to_index(): void
    {
        $this->searchIndex->add('1', 'test document content');

        $this->assertDatabaseHas('search_terms', [
            'document_id' => '1',
            'term' => 'test'
        ]);
    }

    public function test_finds_matching_documents(): void
    {
        $this->searchIndex->add('1', 'test document content');
        $this->searchIndex->add('2', 'another test document');
        
        $results = $this->searchIndex->search(['test', 'document']);
        
        $this->assertCount(2, $results);
        $this->assertContains('1', $results);
        $this->assertContains('2', $results);
    }

    public function test_removes_duplicate_terms(): void
    {
        $this->searchIndex->add('1', 'test test test');

        $count = DB::table('search_terms')
            ->where('document_id', '1')
            ->where('term', 'test')
            ->count();

        $this->assertEquals(1, $count);
    }
}
