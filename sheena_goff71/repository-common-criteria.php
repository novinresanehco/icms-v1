<?php

namespace App\Core\Repository\Criteria;

use Illuminate\Database\Eloquent\Model;
use App\Core\Repository\Contracts\RepositoryInterface;

class OrderByCriteria implements CriteriaInterface
{
    /**
     * @param string $column
     * @param string $direction
     */
    public function __construct(
        private string $column,
        private string $direction = 'asc'
    ) {}

    /**
     * @inheritDoc
     */
    public function apply(Model $model, RepositoryInterface $repository): mixed
    {
        return $model->orderBy($this->column, $this->direction);
    }
}

class WhereCriteria implements CriteriaInterface
{
    /**
     * @param string $column
     * @param mixed $value
     * @param string $operator
     */
    public function __construct(
        private string $column,
        private mixed $value,
        private string $operator = '='
    ) {}

    /**
     * @inheritDoc
     */
    public function apply(Model $model, RepositoryInterface $repository): mixed
    {
        return $model->where($this->column, $this->operator, $this->value);
    }
}

class WhereInCriteria implements CriteriaInterface
{
    /**
     * @param string $column
     * @param array $values
     */
    public function __construct(
        private string $column,
        private array $values
    ) {}

    /**
     * @inheritDoc
     */
    public function apply(Model $model, RepositoryInterface $repository): mixed
    {
        return $model->whereIn($this->column, $this->values);
    }
}

class WithRelationsCriteria implements CriteriaInterface
{
    /**
     * @param array $relations
     */
    public function __construct(private array $relations) {}

    /**
     * @inheritDoc
     */
    public function apply(Model $model, RepositoryInterface $repository): mixed
    {
        return $model->with($this->relations);
    }
}

class SearchCriteria implements CriteriaInterface
{
    /**
     * @param array $searchableFields
     * @param string $searchTerm
     */
    public function __construct(
        private array $searchableFields,
        private string $searchTerm
    ) {}

    /**
     * @inheritDoc
     */
    public function apply(Model $model, RepositoryInterface $repository): mixed
    {
        return $model->where(function ($query) {
            foreach ($this->searchableFields as $field) {
                $query->orWhere($field, 'LIKE', "%{$this->searchTerm}%");
            }
        });
    }
}

class ActiveRecordsCriteria implements CriteriaInterface
{
    /**
     * @inheritDoc
     */
    public function apply(Model $model, RepositoryInterface $repository): mixed
    {
        return $model->where('is_active', true);
    }
}

class PublishedContentCriteria implements CriteriaInterface
{
    /**
     * @inheritDoc
     */
    public function apply(Model $model, RepositoryInterface $repository): mixed
    {
        return $model->where('status', 'published')
            ->where('published_at', '<=', now());
    }
}
