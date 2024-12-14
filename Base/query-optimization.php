<?php

namespace App\Core\Repositories\Query;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use App\Core\Contracts\QueryOptimizerInterface;

class QueryOptimizer implements QueryOptimizerInterface
{
    protected array $config;
    protected array $queryMetrics = [];

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'enable_eager_loading' => true,
            'chunk_size' => 1000,
            'use_select_columns' => true,
            'index_hints' => true
        ], $config);
    }

    public function optimizeQuery(Builder $query, array $options = []): Builder
    {
        $startTime = microtime(true);

        // Apply select columns optimization
        if ($this->config['use_select_columns']) {
            $this->optimizeSelect($query, $options['select'] ?? []);
        }

        // Apply eager loading optimization
        if ($this->config['enable_eager_loading']) {
            $this->optimizeEagerLoading($query, $options['with'] ?? []);
        }

        // Apply index hints
        if ($this->config['index_hints']) {
            $this->applyIndexHints($query, $options['indexes'] ?? []);
        }

        // Record query metrics
        $this->recordQueryMetrics($query, $startTime);

        return $query;
    }

    protected function optimizeSelect(Builder $query, array $columns): void
    {
        if (empty($columns)) {
            // Get minimal required columns
            $model = $query->getModel();
            $columns = array_merge(
                [$model->getKeyName()],
                $model->getFillable(),
                $model->getDates()
            );
        }

        $query->select(array_unique($columns));
    }

    protected function optimizeEagerLoading(Builder $query, array $relations): void
    {
        if (!empty($relations)) {
            foreach ($relations as $relation => $constraints) {
                if (is_numeric($relation)) {
                    $query->with($constraints);
                } else {
                    $query->with([$relation => function ($query) use ($constraints) {
                        if (isset($constraints['select'])) {
                            $query->select($constraints['select']);
                        }
                        if (isset($constraints['where'])) {
                            $query->where($constraints['where']);
                        }
                    }]);
                }
            }
        }
    }

    protected function applyIndexHints(Builder $query, array $indexes): void
    {
        if (!empty($indexes)) {
            $query->from($query->getModel()->getTable() . ' USE INDEX (' . implode(',', $indexes) . ')');
        }
    }

    protected function recordQueryMetrics(Builder $query, float $startTime): void
    {
        $endTime = microtime(true);
        $duration = $endTime - $startTime;

        $this->queryMetrics[] = [
            'query' => $query->toSql(),
            'bindings' => $query->getBindings(),
            'duration' => $duration,
            'timestamp' => now()
        ];
    }

    public function getQueryMetrics(): array
    {
        return $this->queryMetrics;
    }
}

// Optimized Repository Implementation
trait OptimizedQueries
{
    protected QueryOptimizer $optimizer;

    public function findOptimized($id, array $options = []): ?Model
    {
        $query = $this->model->newQuery();
        
        $query = $this->optimizer->optimizeQuery($query, array_merge(
            ['with' => $this->defaultRelations],
            $options
        ));

        return $query->find($id);
    }

    public function getAllOptimized(array $options = []): Collection
    {
        $query = $this->model->newQuery();
        
        $query = $this->optimizer->optimizeQuery($query, array_merge(
            ['with' => $this->defaultRelations],
            $options
        ));

        if ($this->optimizer->config['chunk_size']) {
            return $this->getChunked($query);
        }

        return $query->get();
    }

    protected function getChunked(Builder $query): Collection
    {
        $results = new Collection();
        
        $query->chunk($this->optimizer->config['chunk_size'], function ($chunk) use ($results) {
            $results->push(...$chunk);
        });

        return $results;
    }
}
