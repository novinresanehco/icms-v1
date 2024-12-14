<?php

namespace App\Core\Repositories\Query;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class RepositoryQueryBuilder
{
    protected Builder $query;
    protected array $appliedCriteria = [];
    protected array $appliedScopes = [];

    public function __construct(Builder $query)
    {
        $this->query = $query;
    }

    public function applyCriteria(array $criteria): self
    {
        foreach ($criteria as $criterion) {
            if ($this->shouldApplyCriterion($criterion)) {
                $this->appliedCriteria[] = get_class($criterion);
                $criterion->apply($this->query);
            }
        }

        return $this;
    }

    public function applyScopes(array $scopes): self
    {
        foreach ($scopes as $scope) {
            if ($this->shouldApplyScope($scope)) {
                $this->appliedScopes[] = get_class($scope);
                $scope->apply($this->query);
            }
        }

        return $this;
    }

    public function withRelations(array $relations): self
    {
        $this->query->with($relations);
        return $this;
    }

    public function orderBy(string $column, string $direction = 'asc'): self
    {
        $this->query->orderBy($column, $direction);
        return $this;
    }

    public function paginate(?int $perPage = null): Collection
    {
        return $this->query->paginate($perPage ?? config('repository.pagination.per_page'));
    }

    protected function shouldApplyCriterion($criterion): bool
    {
        return !in_array(get_class($criterion), $this->appliedCriteria);
    }

    protected function shouldApplyScope($scope): bool
    {
        return !in_array(get_class($scope), $this->appliedScopes);
    }
}

abstract class QueryCriterion
{
    protected array $parameters;

    public function __construct(array $parameters = [])
    {
        $this->parameters = $parameters;
    }

    abstract public function apply(Builder $query): void;
}

class WhereInCriterion extends QueryCriterion
{
    public function apply(Builder $query): void
    {
        $query->whereIn(
            $this->parameters['column'],
            $this->parameters['values']
        );
    }
}

class DateRangeCriterion extends QueryCriterion
{
    public function apply(Builder $query): void
    {
        $query->whereBetween(
            $this->parameters['column'],
            [$this->parameters['start'], $this->parameters['end']]
        );
    }
}

class SearchCriterion extends QueryCriterion
{
    public function apply(Builder $query): void
    {
        $searchTerm = $this->parameters['term'];
        $columns = $this->parameters['columns'];

        $query->where(function ($query) use ($searchTerm, $columns) {
            foreach ($columns as $column) {
                $query->orWhere($column, 'LIKE', "%{$searchTerm}%");
            }
        });
    }
}

abstract class QueryScope
{
    abstract public function apply(Builder $query): void;
}

class PublishedScope extends QueryScope
{
    public function apply(Builder $query): void
    {
        $query->where('status', 'published');
    }
}

class ActiveScope extends QueryScope
{
    public function apply(Builder $query): void
    {
        $query->where('active', true);
    }
}

class OrderByRecentScope extends QueryScope
{
    public function apply(Builder $query): void
    {
        $query->orderBy('created_at', 'desc');
    }
}
