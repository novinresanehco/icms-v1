<?php

namespace App\Core\Search\Models;

class SearchResults
{
    public array $results;
    public int $total;
    public int $page;
    public int $perPage;

    public function __construct(array $data)
    {
        $this->results = $data['results'];
        $this->total = $data['total'];
        $this->page = $data['page'];
        $this->perPage = $data['per_page'];
    }

    public function hasMore(): bool
    {
        return $this->total > ($this->page * $this->perPage);
    }

    public function totalPages(): int
    {
        return ceil($this->total / $this->perPage);
    }

    public function toArray(): array
    {
        return [
            'results' => $this->results,
            'total' => $this->total,
            'page' => $this->page,
            'per_page' => $this->perPage,
            'has_more' => $this->hasMore(),
            'total_pages' => $this->totalPages()
        ];
    }
}

namespace App\Core\Search\Traits;

trait Searchable
{
    public static function bootSearchable(): void
    {
        static::created(function ($model) {
            $model->indexDocument();
        });

        static::updated(function ($model) {
            $model->updateSearchIndex();
        });

        static::deleted(function ($model) {
            $model->removeFromSearch();
        });
    }

    public function indexDocument(): void
    {
        app(SearchServiceInterface::class)->indexDocument($this);
    }

    public function updateSearchIndex(): void
    {
        app(SearchServiceInterface::class)->updateDocument($this);
    }

    public function removeFromSearch(): void
    {
        app(SearchServiceInterface::class)->removeDocument($this);
    }

    public function getSearchableId(): string
    {
        return $this->getKey();
    }

    public function getSearchableType(): string
    {
        return static::class;
    }

    public function toSearchArray(): array
    {
        return [
            'id' => $this->getKey(),
            'type' => static::class,
            'title' => $this->title ?? null,
            'content' => $this->content ?? null,
            'tags' => $this->tags->pluck('name')->toArray(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'metadata' => $this->getSearchMetadata()
        ];
    }

    protected function getSearchMetadata(): array
    {
        return [];
    }
}

namespace App\Core\Search\Models;

class Index
{
    protected string $name;
    protected Client $client;
    protected array $settings;
    protected array $mappings;

    public function __construct(
        string $name,
        Client $client,
        array $settings = [],
        array $mappings = []
    ) {
        $this->name = $name;
        $this->client = $client;
        $this->settings = $settings;
        $this->mappings = $mappings;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function exists(): bool
    {
        return $this->client->indices()->exists(['index' => $this->name]);
    }

    public function create(array $params = []): array
    {
        return $this->client->indices()->create([
            'index' => $this->name,
            'body' => [
                'settings' => $params['settings'] ?? $this->settings,
                'mappings' => $params['mappings'] ?? $this->mappings
            ]
        ]);
    }

    public function delete(): array
    {
        return $this->client->indices()->delete(['index' => $this->name]);
    }

    public function index(array $params): array
    {
        return $this->client->index($params);
    }

    public function update(array $params): array
    {
        return $this->client->update($params);
    }
}
