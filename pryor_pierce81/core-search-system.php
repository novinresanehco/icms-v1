<?php

namespace App\Core\Search;

use App\Core\Security\SecurityManager;
use App\Core\Cache\CacheManager;
use App\Core\Exceptions\{SearchException, SecurityException};
use Illuminate\Support\Facades\DB;

class SearchManager implements SearchInterface
{
    private SecurityManager $security;
    private CacheManager $cache;
    private IndexManager $index;
    private QueryBuilder $queryBuilder;
    private array $config;

    public function __construct(
        SecurityManager $security,
        CacheManager $cache,
        IndexManager $index,
        QueryBuilder $queryBuilder,
        array $config
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->index = $index;
        $this->queryBuilder = $queryBuilder;
        $this->config = $config;
    }

    public function search(SearchQuery $query, SecurityContext $context): SearchResult
    {
        return $this->security->executeCriticalOperation(
            new SearchOperation(
                $query,
                $this->index,
                $this->queryBuilder,
                $context
            ),
            $context
        );
    }

    public function indexContent(Indexable $content): void
    {
        $this->security->executeCriticalOperation(
            new IndexOperation(
                $content,
                $this->index
            ),
            SecurityContext::system()
        );
    }

    public function updateIndex(Indexable $content): void
    {
        $this->security->executeCriticalOperation(
            new UpdateIndexOperation(
                $content,
                $this->index
            ),
            SecurityContext::system()
        );
    }

    public function deleteFromIndex(Indexable $content): void
    {
        $this->security->executeCriticalOperation(
            new DeleteIndexOperation(
                $content,
                $this->index
            ),
            SecurityContext::system()
        );
    }

    public function suggest(string $query, array $options = []): array
    {
        return $this->cache->remember(
            $this->getSuggestionCacheKey($query, $options),
            fn() => $this->index->suggest($query, $options)
        );
    }

    private function getSuggestionCacheKey(string $query, array $options): string
    {
        return 'search_suggest.' . md5($query . serialize($options));
    }
}

class IndexManager
{
    private DB $database;
    private Analyzer $analyzer;
    private array $config;

    public function __construct(DB $database, Analyzer $analyzer, array $config)
    {
        $this->database = $database;
        $this->analyzer = $analyzer;
        $this->config = $config;
    }

    public function index(Indexable $content): void
    {
        $tokens = $this->analyzer->analyze($content->getIndexableContent());
        $metadata = $content->getIndexableMetadata();

        DB::transaction(function() use ($content, $tokens, $metadata) {
            $this->deleteExisting($content);
            $this->insertTokens($content, $tokens);
            $this->insertMetadata($content, $metadata);
        });
    }

    public function search(SearchQuery $query, SecurityContext $context): SearchResult
    {
        $tokens = $this->analyzer->analyze($query->getQuery());
        
        $results = $this->database->table('search_index')
            ->join('search_metadata', 'search_index.content_id', '=', 'search_metadata.content_id')
            ->whereIn('token', $tokens)
            ->where(function($q) use ($query) {
                foreach ($query->getFilters() as $field => $value) {
                    $q->where("metadata.{$field}", $value);
                }
            })
            ->select([
                'search_index.content_id',
                DB::raw('COUNT(*) as relevance'),
                'search_metadata.*'
            ])
            ->groupBy('search_index.content_id')
            ->orderBy('relevance', 'desc')
            ->limit($query->getLimit())
            ->offset($query->getOffset())
            ->get();

        return new SearchResult($results);
    }

    public function suggest(string $query, array $options = []): array
    {
        $prefix = $this->analyzer->normalize($query);
        
        return $this->database->table('search_index')
            ->where('token', 'like', $prefix . '%')
            ->groupBy('token')
            ->orderByRaw('COUNT(*) DESC')
            ->limit($options['limit'] ?? 10)
            ->pluck('token')
            ->toArray();
    }

    private function deleteExisting(Indexable $content): void
    {
        $this->database->table('search_index')
            ->where('content_id', $content->getId())
            ->delete();

        $this->database->table('search_metadata')
            ->where('content_id', $content->getId())
            ->delete();
    }

    private function insertTokens(Indexable $content, array $tokens): void
    {
        $records = array_map(fn($token) => [
            'content_id' => $content->getId(),
            'token' => $token,
            'created_at' => now()
        ], $tokens);

        $this->database->table('search_index')->insert($records);
    }

    private function insertMetadata(Indexable $content, array $metadata): void
    {
        $metadata['content_id'] = $content->getId();
        $metadata['created_at'] = now();

        $this->database->table('search_metadata')->insert($metadata);
    }
}

class Analyzer
{
    private array $config;
    private array $stopWords;

    public function analyze(string $text): array
    {
        $normalized = $this->normalize($text);
        $tokens = $this->tokenize($normalized);
        $tokens = $this->removeStopWords($tokens);
        return $this->stem($tokens);
    }

    public function normalize(string $text): string
    {
        $text = mb_strtolower($text);
        $text = strip_tags($text);
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);
        return trim(preg_replace('/\s+/', ' ', $text));
    }

    private function tokenize(string $text): array
    {
        return explode(' ', $text);
    }

    private function removeStopWords(array $tokens): array
    {
        return array_diff($tokens, $this->stopWords);
    }

    private function stem(array $tokens): array
    {
        return array_map([$this, 'stemWord'], $tokens);
    }

    private function stemWord(string $word): string
    {
        // Implement stemming algorithm (e.g., Porter Stemmer)
        return $word;
    }
}

class SearchQuery
{
    private string $query;
    private array $filters;
    private int $limit;
    private int $offset;

    public function __construct(
        string $query,
        array $filters = [],
        int $limit = 10,
        int $offset = 0
    ) {
        $this->query = $query;
        $this->filters = $filters;
        $this->limit = min($limit, 100);
        $this->offset = max($offset, 0);
    }

    public function getQuery(): string
    {
        return $this->query;
    }

    public function getFilters(): array
    {
        return $this->filters;
    }

    public function getLimit(): int
    {
        return $this->limit;
    }

    public function getOffset(): int
    {
        return $this->offset;
    }
}
