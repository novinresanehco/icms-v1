<?php

namespace App\Core\Search;

use Illuminate\Support\Facades\Cache;
use App\Core\Security\SecurityManagerInterface;
use App\Core\Repository\SearchRepositoryInterface;
use App\Core\Validation\ValidationService;
use App\Core\Exceptions\{ValidationException, UnauthorizedException};

class OptimizedSearchService implements SearchServiceInterface
{
    private SecurityManagerInterface $security;
    private SearchRepositoryInterface $repository;
    private ValidationService $validator;
    private SearchIndex $searchIndex;
    
    public function __construct(
        SecurityManagerInterface $security,
        SearchRepositoryInterface $repository,
        ValidationService $validator,
        SearchIndex $searchIndex
    ) {
        $this->security = $security;
        $this->repository = $repository;
        $this->validator = $validator;
        $this->searchIndex = $searchIndex;
    }

    /**
     * Execute a secured and optimized search operation
     *
     * @throws ValidationException
     * @throws UnauthorizedException
     */
    public function search(string $query, array $filters = []): array
    {
        // Validate input
        $this->validator->validate(compact('query', 'filters'), [
            'query' => 'required|string|min:2|max:200',
            'filters' => 'array'
        ]);

        // Security check
        if (!$this->security->hasPermission('search.execute')) {
            throw new UnauthorizedException('Insufficient permissions for search operation');
        }

        // Generate cache key
        $cacheKey = $this->buildCacheKey($query, $filters);

        // Check cache first
        return Cache::remember($cacheKey, config('search.cache_ttl', 3600), function() use ($query, $filters) {
            return $this->executeSearch($query, $filters);
        });
    }

    /**
     * Index content for searching with security checks and validation
     */
    public function index(string $id, string $content, array $metadata = []): void
    {
        // Validate input
        $this->validator->validate(compact('id', 'content', 'metadata'), [
            'id' => 'required|string|max:100',
            'content' => 'required|string|max:100000',
            'metadata' => 'array'
        ]);

        // Security check
        if (!$this->security->hasPermission('content.index')) {
            throw new UnauthorizedException('Insufficient permissions for content indexing');
        }

        // Execute in transaction
        DB::transaction(function() use ($id, $content, $metadata) {
            // Store in main repository
            $this->repository->store([
                'id' => $id,
                'content' => $content,
                'metadata' => $metadata,
                'created_at' => now()
            ]);

            // Update search index
            $this->searchIndex->add($id, $content);
            
            // Clear relevant caches
            $this->clearRelatedCaches($id);
        });
    }

    /**
     * Execute the actual search operation
     */
    private function executeSearch(string $query, array $filters): array
    {
        // Get matching document IDs from search index
        $terms = $this->extractSearchTerms($query);
        $documentIds = $this->searchIndex->search($terms);

        // Get full documents with permission filtering
        $results = $this->repository->findByIds($documentIds);

        return array_filter($results, fn($result) => 
            $this->security->hasPermission("view.{$result->type}")
        );
    }

    /**
     * Extract and normalize search terms
     */
    private function extractSearchTerms(string $query): array
    {
        $words = str_word_count(strtolower($query), 1);
        return array_unique(array_filter($words, fn($word) => strlen($word) > 2));
    }

    /**
     * Build cache key for search operation
     */
    private function buildCacheKey(string $query, array $filters): string
    {
        $userId = $this->security->getCurrentUser()->id;
        $filterHash = md5(json_encode($filters));
        return "search:{$userId}:{$filterHash}:" . md5($query);
    }

    /**
     * Clear related caches when content is indexed
     */
    private function clearRelatedCaches(string $id): void
    {
        // Clear specific content caches
        Cache::tags(['content', "content:{$id}"])->flush();
        
        // Clear search result caches that might contain this content
        Cache::tags(['search'])->flush();
    }
}
