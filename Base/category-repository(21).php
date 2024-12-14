<?php

namespace App\Core\Repositories\Contracts;

use App\Models\Category;
use Illuminate\Database\Eloquent\Collection;

interface CategoryRepositoryInterface extends RepositoryInterface
{
    public function findBySlug(string $slug): ?Category;
    public function getTree(): Collection;
    public function getChildren(int $parentId): Collection;
    public function reorder(int $id, int $newPosition): void;
    public function moveToParent(int $id, ?int $parentId): Category;
}

namespace App\Core\Repositories;

use App\Core\Repositories\Contracts\CategoryRepositoryInterface;
use App\Models\Category;
use Illuminate\Database\Eloquent\Collection;

class CategoryRepository extends BaseRepository implements CategoryRepositoryInterface
{
    public function __construct(Category $model)
    {
        parent::__construct($model);
    }

    public function findBySlug(string $slug): ?Category
    {
        return $this->model->where('slug', $slug)->first();
    }

    public function getTree(): Collection
    {
        return $this->model->whereNull('parent_id')
            ->with(['children' => function ($query) {
                $query->orderBy('order');
            }])
            ->orderBy('order')
            ->get();
    }

    public function getChildren(int $parentId): Collection
    {
        return $this->model->where('parent_id', $parentId)
            ->orderBy('order')
            ->get();
    }

    public function reorder(int $id, int $newPosition): void
    {
        $category = $this->findOrFail($id);
        $this->model->where('parent_id', $category->parent_id)
            ->where('order', '>=', $newPosition)
            ->increment('order');
            
        $category->order = $newPosition;
        $category->save();
    }

    public function moveToParent(int $id, ?int $parentId): Category
    {
        $category = $this->findOrFail($id);
        
        // Verify no circular reference
        if ($parentId) {
            $parent = $this->findOrFail($parentId);
            if ($parent->isDescendantOf($category)) {
                throw new \InvalidArgumentException('Cannot move category to its own descendant');
            }
        }
        
        $category->parent_id = $parentId;
        $category->order = $this->model->where('parent_id', $parentId)->max('order') + 1;
        $category->save();
        
        return $category;
    }
}
