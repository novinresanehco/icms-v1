<?php

namespace App\Core\Repositories;

use App\Core\Repositories\Query\{
    RepositoryQueryBuilder,
    SearchCriterion,
    DateRangeCriterion,
    PublishedScope,
    OrderByRecentScope
};

class ContentRepository extends BaseRepository
{
    public function findPublishedContent(array $parameters = [])
    {
        $builder = new RepositoryQueryBuilder($this->model->newQuery());

        return $builder
            ->applyCriteria([
                new SearchCriterion([
                    'term' => $parameters['search'] ?? '',
                    'columns' => ['title', 'content']
                ]),
                new DateRangeCriterion([
                    'column' => 'published_at',
                    'start' => $parameters['date_from'] ?? null,
                    'end' => $parameters['date_to'] ?? null
                ])
            ])
            ->applyScopes([
                new PublishedScope(),
                new OrderByRecentScope()
            ])
            ->withRelations(['author', 'categories'])
            ->paginate($parameters['per_page'] ?? null);
    }
}

// Usage example in a controller:
class ContentController extends Controller
{
    protected ContentRepository $repository;

    public function index(Request $request)
    {
        $parameters = $request->validate([
            'search' => 'nullable|string',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after:date_from',
            'per_page' => 'nullable|integer|min:1|max:100'
        ]);

        return $this->repository->findPublishedContent($parameters);
    }
}
