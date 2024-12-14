<?php

namespace App\Core\Search;

use Illuminate\Support\Facades\{DB, Cache};
use App\Core\Security\SecurityContext;
use App\Core\Exceptions\SearchException;

class SearchManager
{
    private SecurityManager $security;
    private SearchIndexer $indexer;
    private SearchValidator $validator;
    private QueryBuilder $queryBuilder;
    private ResultFormatter $formatter;
    private array $config;

    public function __construct(
        SecurityManager $security,
        SearchIndexer $indexer,
        SearchValidator $validator,
        QueryBuilder $queryBuilder,
        ResultFormatter $formatter,
        array $config
    ) {
        $this->security = $security;
        $this->indexer = $indexer;
        $this->validator = $validator;
        $this->queryBuilder = $queryBuilder;
        $this->formatter = $formatter;
        $this->config = $config;
    }

    public function search(SearchRequest $request, SecurityContext $context): SearchResult
    {
        return $this->security->executeCriticalOperation(function() use ($request) {
            // Validate request
            $validated = $this->validator->validate($request);
            
            // Check cache
            if ($this->isCacheable($request)) {
                $cached = $this->getFromCache($request);
                if ($cached) {
                    return $this->formatter->format($cached);
                }
            }
            
            // Build query
            $query = $this->queryBuilder->build($validated);
            
            // Execute search
            $results = $this->indexer->search($query);
            
            // Cache results if applicable
            if ($this->isCacheable($request)) {
                $this->cache->put(
                    $this->getCacheKey($request),
                    $results,
                    $this->config['cache_ttl']
                );
            }
            
            return $this->formatter->format($results);
        }, $context);
    }

    public function indexContent(ContentData $content, SecurityContext $context): bool
    {
        return $this->security->executeCriticalOperation(function() use ($content) {
            $processed = $this->preprocessContent($content);
            return $this->indexer->index($processed);
        }, $context);
    }

    public function removeFromIndex(string $contentId, SecurityContext $context): bool
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->indexer->remove($contentId),
            $context
        );
    }

    private function preprocessContent(ContentData $content): array
    {
        $text = strip_tags($content->content);
        $text = preg_replace('/\s+/', ' ', $text);
        
        return [
            'id' => $content->id,
            'title' => $content->title,
            'content' => $text,
            'type' => $content->type,
            'keywords' => $this->extractKeywords($text),
            'timestamp' => time()
        ];
    }

    private function extractKeywords(string $text): array
    {
        $words = str_word_count(strtolower($text), 1);
        $words = array_diff($words, $this->config['stop_words']);
        $frequencies = array_count_values($words);
        arsort($frequencies);
        
        return array_slice(array_keys($frequencies), 0, $this->config['max_keywords']);
    }

    private function isCacheable(SearchRequest $request): bool
    {
        return !$request->isRealtime() && 
               $request->getCacheTime() > 0;
    }

    private function getCacheKey(SearchRequest $request): string
    {
        return "search:" . md5(serialize($request->toArray()));
    }

    private function getFromCache(SearchRequest $request): ?array
    {
        return Cache::get($this->getCacheKey($request));
    }
}

class SearchIndexer
{
    private DB $db;
    private array $config;

    public function search(array $query): array
    {
        $builder = DB::table('search_index')
            ->select($this->getSelectFields($query));
            
        $this->applyFilters($builder, $query);
        $this->applySort($builder, $query);
        
        return $builder
            ->limit($query['limit'])
            ->offset($query['offset'])
            ->get()
            ->toArray();
    }

    public function index(array $data): bool
    {
        return DB::transaction(function() use ($data) {
            // Remove existing entry
            DB::table('search_index')
                ->where('content_id', $data['id'])
                ->delete();
            
            // Insert new entry
            return DB::table('search_index')->insert([
                'content_id' => $data['id'],
                'title' => $data['title'],
                'content' => $data['content'],
                'type' => $data['type'],
                'keywords' => json_encode($data['keywords']),
                'indexed_at' => $data['timestamp']
            ]);
        });
    }

    public function remove(string $contentId): bool
    {
        return DB::table('search_index')
            ->where('content_id', $contentId)
            ->delete() > 0;
    }

    private function getSelectFields(array $query): array
    {
        $fields = ['content_id', 'title', 'type'];
        
        if ($query['include_content']) {
            $fields[] = 'content';
        }
        
        if ($query['include_keywords']) {
            $fields[] = 'keywords';
        }
        
        return $fields;
    }

    private function applyFilters($builder, array $query): void
    {
        if (!empty($query['term'])) {
            $term = $query['term'];
            $builder->where(function($q) use ($term) {
                $q->where('title', 'LIKE', "%{$term}%")
                  ->orWhere('content', 'LIKE', "%{$term}%")
                  ->orWhere('keywords', 'LIKE', "%{$term}%");
            });
        }
        
        if (!empty($query['type'])) {
            $builder->where('type', $query['type']);
        }
        
        if (!empty($query['date_from'])) {
            $builder->where('indexed_at', '>=', $query['date_from']);
        }
        
        if (!empty($query['date_to'])) {
            $builder->where('indexed_at', '<=', $query['date_to']);
        }
    }

    private function applySort($builder, array $query): void
    {
        $field = $query['sort_by'] ?? 'indexed_at';
        $direction = $query['sort_direction'] ?? 'desc';
        
        $builder->orderBy($field, $direction);
    }
}

class SearchValidator
{
    private array $rules = [
        'term' => 'nullable|string|max:255',
        'type' => 'nullable|string|max:50',
        'date_from' => 'nullable|integer',
        'date_to' => 'nullable|integer',
        'sort_by' => 'nullable|in:indexed_at,title,type',
        'sort_direction' => 'nullable|in:asc,desc',
        'limit' => 'integer|min:1|max:100',
        'offset' => 'integer|min:0',
        'include_content' => 'boolean',
        'include_keywords' => 'boolean'
    ];

    public function validate(SearchRequest $request): array
    {
        $data = $request->toArray();
        
        $validator = validator($data, $this->rules);
        
        if ($validator->fails()) {
            throw new ValidationException($validator->errors()->first());
        }
        
        return $validator->validated();
    }
}

class QueryBuilder
{
    private array $config;

    public function build(array $params): array
    {
        return array_merge(
            $this->getDefaults(),
            $this->sanitizeParams($params)
        );
    }

    private function getDefaults(): array
    {
        return [
            'term' => null,
            'type' => null,
            'date_from' => null,
            'date_to' => null,
            'sort_by' => 'indexed_at',
            'sort_direction' => 'desc',
            'limit' => $this->config['default_limit'],
            'offset' => 0,
            'include_content' => false,
            'include_keywords' => false
        ];
    }

    private function sanitizeParams(array $params): array
    {
        foreach ($params as $key => $value) {
            if (is_string($value)) {
                $params[$key] = strip_tags($value);
            }
        }
        return $params;
    }
}

class ResultFormatter
{
    private array $config;

    public function format(array $results): SearchResult
    {
        $formatted = array_map(function($result) {
            return $this->formatResult($result);
        }, $results);
        
        return new SearchResult($formatted);
    }

    private function formatResult($result): array
    {
        $formatted = [
            'id' => $result->content_id,
            'title' => $result->title,
            'type' => $result->type
        ];
        
        if (isset($result->content)) {
            $formatted['content'] = $this->formatContent($result->content);
        }
        
        if (isset($result->keywords)) {
            $formatted['keywords'] = json_decode($result->keywords, true);
        }
        
        return $formatted;
    }

    private function formatContent(string $content): string
    {
        if (strlen($content) > $this->config['excerpt_length']) {
            return substr($content, 0, $this->config['excerpt_length']) . '...';
        }
        return $content;
    }
}

class SearchRequest
{
    private array $params;
    private int $cacheTime;
    private bool $realtime;

    public function isRealtime(): bool
    {
        return $this->realtime;
    }

    public function getCacheTime(): int
    {
        return $this->cacheTime;
    }

    public function toArray(): array
    {
        return $this->params;
    }
}

class SearchResult
{
    private array $results;

    public function __construct(array $results)
    {
        $this->results = $results;
    }

    public function getResults(): array
    {
        return $this->results;
    }

    public function count(): int
    {
        return count($this->results);
    }
}
