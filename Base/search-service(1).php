<?php

namespace App\Core\Services\Search;

use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Searchable;
use App\Core\Contracts\SearchServiceInterface;

interface SearchServiceInterface
{
    public function index(Model $model): void;
    public function update(Model $model): void;
    public function delete(Model $model): void;
    public function search(string $query, array $filters = []): array;
}

class SearchService implements SearchServiceInterface
{
    protected array $config;
    protected $engine;

    public function __construct(array $config = [])
    {
        $this->config = $config;
        $this->initializeEngine();
    }

    public function index(Model $model): void
    {
        if ($this->isSearchable($model)) {
            $model->searchable();
        }
    }

    public function update(Model $model): void
    {
        if ($this->isSearchable($model)) {
            $model->searchable();
        }
    }

    public function delete(Model $model): void
    {
        if ($this->isSearchable($model)) {
            $model->unsearchable();
        }
    }

    public function search(string $query, array $filters = []): array
    {
        $modelClass = $filters['model'] ?? null;
        
        if (!$modelClass) {
            throw new \InvalidArgumentException('Model class must be specified in filters');
        }

        $results = $modelClass::search($query)
            ->when(isset($filters['where']), function($search) use ($filters) {
                return $search->where($filters['where']);
            })
            ->when(isset($filters['orderBy']), function($search) use ($filters) {
                return $search->orderBy($filters['orderBy'][0], $filters['orderBy'][1] ?? 'asc');
            })
            ->paginate($filters['perPage'] ?? 15);

        return [
            'data' => $results->items(),
            'meta' => [
                'total' => $results->total(),
                'per_page' => $results->perPage(),
                'current_page' => $results->currentPage(),
                'last_page' => $results->lastPage()
            ]
        ];
    }

    protected function isSearchable(Model $model): bool
    {
        return in_array(Searchable::class, class_uses_recursive($model));
    }

    protected function initializeEngine(): void
    {
        $engineClass = config('scout.driver');
        $this->engine = new $engineClass($this->config);
    }
}
