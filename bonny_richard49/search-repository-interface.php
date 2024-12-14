<?php

namespace App\Core\Search\Repository;

use App\Core\Search\Models\SearchIndex;
use App\Core\Search\DTO\SearchData;
use App\Core\Shared\Repository\RepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

interface SearchRepositoryInterface extends RepositoryInterface
{
    /**
     * Index content for searching.
     *
     * @param SearchData $data
     * @return SearchIndex
     */
    public function index(SearchData $data): SearchIndex;

    /**
     * Update search index.
     *
     * @param int $id
     * @param SearchData $data
     * @return SearchIndex
     */
    public function updateIndex(int $id, SearchData $data): SearchIndex;

    /**
     * Remove content from search index.
     *
     * @param string $modelType
     * @param int $modelId
     * @return bool
     */
    public function removeFromIndex(string $modelType, int $modelId): bool;

    /**
     * Search content.
     *
     * @param string $query
     * @param array $filters
     * @return LengthAwarePaginator
     */
    public function search(string $query, array $filters = []): LengthAwarePaginator;

    /**
     * Get related content.
     *
     * @param string $modelType
     * @param int $modelId
     * @param int $limit
     * @return Collection
     */
    public function getRelated(string $modelType, int $modelId, int $limit = 5): Collection;

    /**
     * Get popular search terms.
     *
     * @param int $limit
     * @return array
     */
    public function getPopularSearchTerms(int $limit = 10): array;

    /**
     * Log search query.
     *
     * @param string $query
     * @param array $metadata
     * @return bool
     */
    public function logSearch(string $query, array $metadata = []): bool;

    /**
     * Rebuild search index.
     *
     * @param string|null $modelType Specific model type to rebuild
     * @return int Number of indexed items
     */
    public function rebuildIndex(?string $modelType = null): int;

    /**
     * Get indexing statistics.
     *
     * @return array
     */
    public function getIndexingStats(): array;

    /**
     * Suggest search terms.
     *
     * @param string $query
     * @param int $limit
     * @return array
     */
    public function suggestTerms(string $query, int $limit = 5): array;

    /**
     * Get search analytics.
     *
     * @param array $filters
     * @return array
     */
    public function getSearchAnalytics(array $filters = []): array;
}
