<?php

namespace App\Core\Repositories\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

interface SearchableRepositoryInterface
{
    public function search(string $query): Collection;
    public function searchPaginated(string $query, int $perPage = 15): LengthAwarePaginator;
    public function advancedSearch(array $criteria): Collection;
    public function searchInFields(array $fields, string $query): Collection;
}

namespace App\Core\Repositories\Traits;

use Laravel\Scout\Searchable as ScoutSearchable;

trait SearchableModel
{
    use ScoutSearchable;

    public function shouldBeSearchable(): bool
    {
        return $this->isPublished ?? true;
    }

    public function toSearchableArray(): array
    {
        return array_merge($this->toArray(), [
            'created_at' => $this->created_at?->timestamp,
            'updated_at' => $this->updated_at?->timestamp,
        ]);
    }
}

namespace App\Core\Repositories\Decorators;

use App\Core\Repositories\Contracts\{RepositoryInterface, SearchableRepositoryInterface};
use Illuminate\Database\Eloquent\{Model, Collection};
use Illuminate\Pagination\LengthAwarePaginator;

class SearchableRepository implements RepositoryInterface, SearchableRepositoryInterface
{
    protected RepositoryInterface $repository;
    protected array $searchableFields = ['title', 'content', 'metadata'];

    public function __construct(RepositoryInterface $repository)
    {
        $this->repository = $repository;
    }

    public function search(string $query): Collection
    {
        return $this->repository->getModel()
            ->search($query)
            ->get();
    }

    public function searchPaginated(string $query, int $perPage = 15): LengthAwarePaginator
    {
        return $this->repository->getModel()
            ->search($query)
            ->paginate($perPage);
    }

    public function advancedSearch(array $criteria): Collection
    {
        $query = $this->repository->getModel()->search('');

        foreach ($criteria as $field => $value) {
            if (is_array($value)) {
                $query->where($field, $value['operator'], $value['value']);
            } else {
                $query->where($field, $value);
            }
        }

        return $query->get();
    }

    public function searchInFields(array $fields, string $query): Collection
    {
        return $this->repository->getModel()
            ->search($query)
            ->within($fields)
            ->get();
    }

    // Delegate other methods to repository
    public function __call($method, $arguments)
    {
        return $this->repository->{$method}(...$arguments);
    }
}
