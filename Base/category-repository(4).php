<?php

namespace App\Repositories;

use App\Models\Category;
use App\Repositories\Contracts\CategoryRepositoryInterface;
use Illuminate\Support\Collection;

class CategoryRepository extends BaseRepository implements CategoryRepositoryInterface 
{
    protected array $searchableFields = ['name', 'slug', 'description'];
    protected array $filterableFields = ['parent_id', 'status'];
    protected array $relationships = ['parent', 'children', 'contents'];

    public function __construct(Category $model)
    {
        parent::__construct($model);
    }

    public function findBySlug(string $slug): ?Category
    {
        return Cache::remember(
            $this->getCacheKey("slug.{$slug}"),
            $this->cacheTTL,
            fn() => $this->model->with($this->relationships)->where('slug', $slug)->first()
        );
    }

    public function getTree(): Collection
    {
        return Cache::remember(
            $this->getCacheKey('tree'),
            $this->cacheTTL,
            fn() => $this->model->with('children')->whereNull('parent_id')->get()
        );
    }

    public function getWithContentsCount(): Collection
    {
        return Cache::remember(
            $this->getCacheKey('with_contents_count'),
            $this->cacheTTL,
            fn() => $this->model->withCount('contents')->get()
        );
    }

    public function moveToParent(int $id, ?int $parentId): Category
    {
        $category = $this->findOrFail($id);
        $category->parent_id = $parentId;
        $category->save();
        
        $this->clearModelCache();
        return $category;
    }
}
