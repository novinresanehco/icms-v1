<?php

namespace App\Core\Repositories\Criteria;

use Illuminate\Database\Eloquent\Builder;

interface CriteriaInterface
{
    public function apply(Builder $query): Builder;
}

class WithTrashedCriteria implements CriteriaInterface
{
    public function apply(Builder $query): Builder
    {
        return $query->withTrashed();
    }
}

class ActiveStatusCriteria implements CriteriaInterface
{
    public function apply(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }
}

class OrderByCriteria implements CriteriaInterface
{
    private string $column;
    private string $direction;

    public function __construct(string $column, string $direction = 'asc')
    {
        $this->column = $column;
        $this->direction = $direction;
    }

    public function apply(Builder $query): Builder
    {
        return $query->orderBy($this->column, $this->direction);
    }
}

class DateRangeCriteria implements CriteriaInterface
{
    private string $column;
    private string $startDate;
    private string $endDate;

    public function __construct(string $column, string $startDate, string $endDate)
    {
        $this->column = $column;
        $this->startDate = $startDate;
        $this->endDate = $endDate;
    }

    public function apply(Builder $query): Builder
    {
        return $query->whereBetween($this->column, [
            $this->startDate,
            $this->endDate
        ]);
    }
}

class SearchCriteria implements CriteriaInterface
{
    private string $search;
    private array $columns;

    public function __construct(string $search, array $columns)
    {
        $this->search = $search;
        $this->columns = $columns;
    }

    public function apply(Builder $query): Builder
    {
        return $query->where(function($query) {
            foreach ($this->columns as $column) {
                $query->orWhere($column, 'LIKE', "%{$this->search}%");
            }
        });
    }
}

trait HasCriteria
{
    protected array $criteria = [];

    public function addCriterion(CriteriaInterface $criterion): self
    {
        $this->criteria[] = $criterion;
        return $this;
    }

    public function applyCriteria(Builder $query): Builder
    {
        foreach ($this->criteria as $criterion) {
            $query = $criterion->apply($query);
        }
        return $query;
    }

    public function resetCriteria(): self
    {
        $this->criteria = [];
        return $this;
    }
}
