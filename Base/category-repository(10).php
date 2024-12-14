<?php

namespace App\Core\Repositories;

use App\Core\Repositories\Contracts\CategoryRepositoryInterface;
use App\Models\Category;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CategoryRepository implements CategoryRepositoryInterface
{
    protected Category $model;

    public function __construct(Category $model)
    {
        $this->model = $model;
    }

    public function find(int $id): ?Category
    {
        return $this->model->find($id);
    }

    public function findBySlug(string $slug): ?Category
    {
        return $this->model->where('slug', $slug)->first();
    }

    public function all(): Collection
    {
        return $this->model->orderBy('order')->get();
    }

    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return $this->model->orderBy('order')->paginate($perPage);
    }

    public function create(array $data): Category
    {
        DB::beginTransaction();
        try {
            $data['slug'] = $data['slug'] ?? Str::slug($data['name']);
            $data['order'] = $this->model->max('order') + 1;
            
            $category = $this->model->create($data);
            
            if (!empty($data['meta'])) {
                $category->meta()->createMany($data['meta']);
            }
            
            DB::commit();
            return $category;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function update(int $id, array $data): Category
    {
        DB::beginTransaction();
        try {
            $category = $this->model->findOrFail($id);
            
            if (!empty($data['name']) && $category->name !== $data['name']) {
                $data['slug'] = $data['slug'] ?? Str::slug($data['name']);
            }
            
            $category->update($data);
            
            if (isset($data['meta'])) {
                $category->meta()->delete();
                $category->meta()->createMany($data['meta']);
            }
            
            DB::commit();
            return $category;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function delete(int $id): bool
    {
        DB::beginTransaction();
        try {
            $category = $this->model->findOrFail($id);
            $category->meta()->delete();
            $category->delete();
            
            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function getTree(): Collection
    {
        return $this->model->whereNull('parent_id')
            ->with('children')
            ->orderBy('order')
            ->get();
    }

    public function getChildren(int $parentId): Collection
    {
        return $this->model->where('parent_id', $parentId)
            ->orderBy('order')
            ->get();
    }

    public function getParents(int $categoryId): Collection
    {
        $parents = collect();
        $category = $this->find($categoryId);

        while ($category && $category->parent_id) {
            $category = $this->find($category->parent_id);
            if ($category) {
                $parents->push($category);
            }
        }

        return $parents->reverse();
    }

    public function reorder(array $order): bool
    {
        DB::beginTransaction();
        try {
            foreach ($order as $position => $id) {
                $this->model->where('id', $id)->update(['order' => $position]);
            }
            
            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function moveToParent(int $categoryId, ?int $parentId): bool
    {
        DB::beginTransaction();
        try {
            $category = $this->model->findOrFail($categoryId);
            $category->parent_id = $parentId;
            $category->save();
            
            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
