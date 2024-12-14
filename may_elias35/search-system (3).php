// File: app/Core/Search/Manager/SearchManager.php
<?php

namespace App\Core\Search\Manager;

class SearchManager
{
    protected SearchEngineFactory $engineFactory;
    protected IndexManager $indexManager;
    protected SearchCache $cache;
    protected SearchConfig $config;

    public function search(SearchRequest $request): SearchResult
    {
        $engine = $this->engineFactory->create($request->getType());
        
        if ($cachedResult = $this->cache->get($request)) {
            return $cachedResult;
        }

        $result = $engine->search(
            $request->getQuery(),
            $request->getFilters(),
            $request->getOptions()
        );

        $this->cache->put($request, $result);
        return $result;
    }

    public function index(Searchable $entity): void
    {
        $this->indexManager->index($entity);
        $this->cache->invalidate($entity);
    }

    public function reindex(string $type): void
    {
        $this->indexManager->reindex($type);
        $this->cache->flush($type);
    }
}

// File: app/Core/Search/Engine/ElasticsearchEngine.php
<?php

namespace App\Core\Search\Engine;

class ElasticsearchEngine implements SearchEngine
{
    protected Client $client;
    protected QueryBuilder $queryBuilder;
    protected ResultFormatter $formatter;

    public function search(string $query, array $filters = [], array $options = []): SearchResult
    {
        $searchQuery = $this->queryBuilder
            ->setQuery($query)
            ->setFilters($filters)
            ->setOptions($options)
            ->build();

        $response = $this->client->search($searchQuery);
        
        return $this->formatter->format($response);
    }

    public function index(array $documents): void
    {
        $operations = [];
        foreach ($documents as $document) {
            $operations[] = [
                'index' => [
                    '_index' => $document->getSearchIndex(),
                    '_id' => $document->getSearchId()
                ]
            ];
            $operations[] = $document->toSearchArray();
        }

        $this->client->bulk(['body' => $operations]);
    }
}

// File: app/Core/Search/Query/QueryBuilder.php
<?php

namespace App\Core\Search\Query;

class QueryBuilder
{
    protected array $query = [];
    protected array $filters = [];
    protected array $options = [];
    protected QueryConfig $config;

    public function setQuery(string $query): self
    {
        $this->query = [
            'multi_match' => [
                'query' => $query,
                'fields' => $this->config->getSearchFields(),
                'type' => 'most_fields',
                'fuzziness' => 'AUTO'
            ]
        ];

        return $this;
    }

    public function setFilters(array $filters): self
    {
        foreach ($filters as $field => $value) {
            $this->addFilter($field, $value);
        }

        return $this;
    }

    protected function addFilter(string $field, $value): void
    {
        $this->filters[] = [
            'term' => [
                $field => $value
            ]
        ];
    }

    public function build(): array
    {
        $query = [
            'bool' => [
                'must' => [$this->query]
            ]
        ];

        if (!empty($this->filters)) {
            $query['bool']['filter'] = $this->filters;
        }

        return array_merge(['query' => $query], $this->options);
    }
}

// File: app/Core/Search/Index/IndexManager.php
<?php

namespace App\Core\Search\Index;

class IndexManager
{
    protected IndexRepository $repository;
    protected IndexBuilder $builder;
    protected IndexConfig $config;

    public function index(Searchable $entity): void
    {
        $indexName = $entity->getSearchIndex();
        $this->ensureIndex($indexName);
        
        $document = $this->builder->build($entity);
        $this->repository->index($indexName, $document);
    }

    public function reindex(string $type): void
    {
        $indexName = $this->config->getIndexName($type);
        $newIndex = $indexName . '_' . time();

        // Create new index
        $this->builder->create($newIndex);

        // Index all documents
        $entities = $this->getEntities($type);
        foreach ($entities as $entity) {
            $document = $this->builder->build($entity);
            $this->repository->index($newIndex, $document);
        }

        // Switch alias
        $this->repository->updateAlias($indexName, $newIndex);
    }

    protected function ensureIndex(string $name): void
    {
        if (!$this->repository->exists($name)) {
            $this->builder->create($name);
        }
    }
}
