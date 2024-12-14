<?php

namespace App\Core\Search\Models;

use Illuminate\Database\Eloquent\Model;

class SearchIndex extends Model
{
    protected $fillable = [
        'model_type',
        'model_id',
        'content',
        'metadata',
        'status'
    ];

    protected $casts = [
        'metadata' => 'array',
        'indexed_at' => 'datetime'
    ];
}

namespace App\Core\Search;

trait Searchable
{
    public function shouldIndex(): bool
    {
        return true;
    }

    public function toSearchArray(): array
    {
        return [
            'id' => $this->id,
            'content' => $this->getSearchableContent(),
            'metadata' => $this->getSearchableMetadata()
        ];
    }

    protected function getSearchableContent(): string
    {
        return implode(' ', $this->only($this->getSearchableFields()));
    }

    protected function getSearchableMetadata(): array
    {
        return [];
    }

    protected function getSearchableFields(): array
    {
        return ['title', 'content', 'description'];
    }
}

namespace App\Core\Search\Services;

use App\Core\Search\Exceptions\SearchException;
use App\Core\Search\Models\SearchIndex;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SearchService
{
    private IndexingService $indexer;
    private SearchQueryBuilder $queryBuilder;

    public function __construct(
        IndexingService $indexer,
        SearchQueryBuilder $queryBuilder
    ) {
        $this->indexer = $indexer;
        $this->queryBuilder = $queryBuilder;
    }

    public function index(Model $model): void
    {
        if (!method_exists($model, 'shouldIndex')) {
            throw new SearchException('Model is not searchable');
        }

        if (!$model->shouldIndex()) {
            return;
        }

        try {
            DB::beginTransaction();
            $this->indexer->index($model);
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw new SearchException("Indexing failed: {$e->getMessage()}");
        }
    }

    public function search(string $query, array $filters = []): Collection
    {
        try {
            $searchQuery = $this->queryBuilder
                ->query($query)
                ->filters($filters)
                ->build();

            return SearchIndex::whereRaw($searchQuery->toSql(), $searchQuery->getBindings())
                ->get()
                ->map(function ($index) {
                    return $index->model_type::find($index->model_id);
                })
                ->filter();
        } catch (\Exception $e) {
            throw new SearchException("Search failed: {$e->getMessage()}");
        }
    }

    public function remove(Model $model): void
    {
        try {
            DB::beginTransaction();
            $this->indexer->remove($model);
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw new SearchException("Index removal failed: {$e->getMessage()}");
        }
    }
}

class IndexingService
{
    public function index(Model $model): void
    {
        $searchData = $model->toSearchArray();

        SearchIndex::updateOrCreate(
            [
                'model_type' => get_class($model),
                'model_id' => $model->id
            ],
            [
                'content' => $searchData['content'],
                'metadata' => $searchData['metadata'],
                'indexed_at' => now()
            ]
        );
    }

    public function remove(Model $model): void
    {
        SearchIndex::where([
            'model_type' => get_class($model),
            'model_id' => $model->id
        ])->delete();
    }
}

class SearchQueryBuilder
{
    private string $query = '';
    private array $filters = [];

    public function query(string $query): self
    {
        $this->query = $query;
        return $this;
    }

    public function filters(array $filters): self
    {
        $this->filters = $filters;
        return $this;
    }

    public function build(): \Illuminate\Database\Query\Builder
    {
        $query = SearchIndex::query();

        if ($this->query) {
            $query->whereRaw('MATCH(content) AGAINST(? IN BOOLEAN MODE)', [$this->query]);
        }

        foreach ($this->filters as $field => $value) {
            $query->where("metadata->$field", $value);
        }

        return $query;
    }
}

namespace App\Core\Search\Http\Controllers;

use App\Core\Search\Services\SearchService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    private SearchService $searchService;

    public function __construct(SearchService $searchService)
    {
        $this->searchService = $searchService;
    }

    public function search(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'query' => 'required|string|min:2',
                'filters' => 'array'
            ]);

            $results = $this->searchService->search(
                $request->input('query'),
                $request->input('filters', [])
            );

            return response()->json(['results' => $results]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }
}

namespace App\Core\Search\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use App\Core\Search\Services\SearchService;

class IndexModelJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    private $model;

    public function __construct($model)
    {
        $this->model = $model;
    }

    public function handle(SearchService $searchService): void
    {
        $searchService->index($this->model);
    }
}

namespace App\Core\Search\Observers;

use App\Core\Search\Jobs\IndexModelJob;

class SearchableObserver
{
    public function saved($model): void
    {
        if (method_exists($model, 'shouldIndex') && $model->shouldIndex()) {
            IndexModelJob::dispatch($model);
        }
    }

    public function deleted($model): void
    {
        if (method_exists($model, 'shouldIndex')) {
            SearchIndex::where([
                'model_type' => get_class($model),
                'model_id' => $model->id
            ])->delete();
        }
    }
}
