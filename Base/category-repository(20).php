<?php

namespace App\Repositories;

use App\Models\Category;
use App\Repositories\Contracts\CategoryRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class CategoryRepository implements CategoryRepositoryInterface
{
    protected $model;

    public function __construct(Category $model)
    {
        $this->model = $model;
    }

    public function find(int $id)
    {
        return $this->model->with(['parent', 'children'])->findOrFail($id);
    }

    public function findBySlug(string $slug)
    {
        return $this->model->with(['parent', 'children'])
            ->where('slug', $slug)
            ->firstOrFail();
    }

    public function getAll(array $filters = []): Collection
    {
        return $this->model->with(['parent', 'children'])
            ->when(isset($filters['parent_id']), function ($query) use ($filters) {
                return $query->where('parent_id', $filters['parent_id']);
            })
            ->when(isset($filters['active']), function ($query) use ($filters) {
                return $query->where('is_active', $filters['active']);
            })
            ->orderBy('name')
            ->get();
    }

    public function create(array $data)
    {
        return DB::transaction(function () use ($data) {
            $category = $this->model->create($data);
            return $category->fresh(['parent', 'children']);
        });
    }

    public function update(int $id, array $data)
    {
        return DB::transaction(function () use ($id, $data) {
            $category = $this->find($id);
            $category->update($data);
            return $category->fresh(['parent', 'children']);
        });
    }

    public function delete(int $id): bool
    {
        return DB::transaction(function () use ($id) {
            $category = $this->find($id);
            
            // Move children to parent category if exists
            if ($category->children()->exists()) {
                $category->children()->update(['parent_id' => $category->parent_id]);
            }
            
            return $category->delete();
        });
    }

    public function getTree(): Collection
    {
        return $this->model->with(['children' => function ($query) {
                $query->orderBy('name');
            }])
            ->whereNull('parent_id')
            ->orderBy('name')
            ->get();
    }

    public function getChildren(int $categoryId): Collection
    {
        return $this->model->where('parent_id', $categoryId)
            ->with(['children' => function ($query) {
                $query->orderBy('name');
            }])
            ->orderBy('name')
            ->get();
    }

    public function moveToParent(int $categoryId, ?int $parentId)
    {
        return DB::transaction(function () use ($categoryId, $parentId) {
            $category = $this->find($categoryId);
            
            // Prevent moving to own child
            if ($parentId) {
                $parent = $this->find($parentId);
                if ($parent->isChildOf($category)) {
                    throw new \InvalidArgumentException('Cannot move category to its own child');
                }
            }
            
            $category->update(['parent_id' => $parentId]);
            return $category->fresh(['parent', 'children']);
        });
    }
}
