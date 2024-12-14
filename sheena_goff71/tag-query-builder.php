<?php

namespace App\Core\Tag\Repository;

use App\Core\Tag\Models\Tag;
use Illuminate\Database\Eloquent\Builder;
use App\Core\Tag\Contracts\TagQueryBuilderInterface;

class TagQueryBuilder implements TagQueryBuilderInterface
{
    /**
     * @var Builder
     */
    protected Builder $query;

    public function __construct(Tag $model)
    {
        $this->query = $model->newQuery();
    }

    /**
     * Initialize a new query.
     */
    public function newQuery(): self
    {
        $this->query = Tag::query();
        return $this;
    }

    /**
     * Add search criteria.
     */
    public function withSearch(string $search): self
    {
        $this->query->where(function ($query) use ($search) {
            $query->where('name', 'LIKE', "%{$search}%")
                  ->orWhere('description', 'LIKE', "%{$search}%")
                  ->orWhere('slug', 'LIKE', "%{$search}%");
        });
        return $this;
    }

    /**
     * Add relationship loading.
     */
    public function withRelations(array $relations): self
    {
        $this->query->with($relations);
        return $this;
    }

    /**
     * Add sorting.
     */
    public function withSorting(string $field, string $direction = 'asc'): self
    {
        $this->query->orderBy($field, $direction);
        return $this;
    }

    /**
     * Filter by content type.
     */
    public function forContentType(string $type): self
    {
        $this->query->whereHas('contents', function ($query) use ($type) {
            $query->where('taggable_type', $type);
        });
        return $this;
    }

    /**
     * Filter by minimum usage count.
     */
    public function withMinimumUsage(int $count): self
    {
        $this->query->has('contents', '>=', $count);
        return $this;
    }

    /**
     * Filter by date range.
     */
    public function withinDateRange($startDate, $endDate): self
    {
        $this->query->whereBetween('created_at', [$startDate, $endDate]);
        return $this;
    }

    /**
     * Include usage statistics.
     */
    public function withUsageStats(): self
    {
        $this->query->withCount('contents')
                    ->withMin('contents as first_used', 'created_at')
                    ->withMax('contents as last_used', 'created_at');
        return $this;
    }

    /**
     * Filter active tags.
     */
    public function onlyActive(): self
    {
        $this->query->has('contents');
        return $this;
    }

    /**
     * Filter unused tags.
     */
    public function onlyUnused(): self
    {
        $this->query->doesntHave('contents');
        return $this;
    }

    /**
     * Add custom where clause.
     */
    public function whereCustom(string $column, $operator, $value = null): self
    {
        $this->query->where($column, $operator, $value);
        return $this;
    }

    /**
     * Add having clause for aggregates.
     */
    public function havingCustom(string $column, $operator, $value): self
    {
        $this->query->having($column, $operator, $value);
        return $this;
    }

    /**
     * Get the query builder instance.
     */
    public function getQuery(): Builder
    {
        return $this->query;
    }
}
