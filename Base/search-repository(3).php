<?php

namespace App\Core\Repositories;

use App\Models\SearchIndex;
use App\Core\Services\Cache\CacheService;
use Illuminate\Support\Collection;

class SearchRepository extends AdvancedRepository
{
    protected $model = SearchIndex::class;
    protected $cache;

    public function __construct(CacheService $cache)
    {
        parent::__construct();
        $this->cache = $cache;
    }

    public function search(string $query, array $options = []): Collection
    {
        return $this->executeQuery(function() use ($query, $options) {
            $searchQuery = $this->model->whereRaw('MATCH(title, content) AGAINST(? IN BOOLEAN MODE)', [$query]);

            if (!empty($options['type'])) {
                $searchQuery->where('searchable_type', $options['type']);
            }

            if (!empty($options['filters'])) {
                foreach ($options['filters'] as $field => $value) {
                    $searchQuery->where("metadata->>'{$field}'", $value);
                }
            }

            return $searchQuery
                ->orderByRaw('MATCH(title, content) AGAINST(? IN BOOLEAN MODE) DESC', [$query])
                ->limit($options['limit'] ?? 50)
                ->get();
        });
    }

    public function indexItem($model): void
    {
        $this->executeTransaction(function() use ($model) {
            $this->model->updateOrCreate(
                [
                    'searchable_type' => get_class($model),
                    'searchable_id' => $model->id
                ],
                [
                    'title' => $model->getSearchableTitle(),
                    'content' => $model->getSearchableContent(),
                    'metadata' => $model->getSearchableMetadata(),
                    'indexed_at' => now()
                ]
            );
        });
    }

    public function removeFromIndex($model): void
    {
        $this->executeTransaction(function() use ($model) {
            $this->model
                ->where('searchable_type', get_class($model))
                ->where('searchable_id', $model->id)
                ->delete();
        });
    }

    public function reindexType(string $type): int
    {
        return $this->executeTransaction(function() use ($type) {
            $model = app($type);
            $count = 0;

            $model->chunk(100, function($items) use (&$count) {
                foreach ($items as $item) {
                    $this->indexItem($item);
                    $count++;
                }
            });

            return $count;
        });
    }
}
