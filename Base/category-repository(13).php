<?php

namespace App\Core\Repositories;

use App\Core\Models\Category;
use App\Core\Contracts\Repositories\CategoryRepositoryInterface;
use App\Core\Exceptions\CategoryNotFoundException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CategoryRepository implements CategoryRepositoryInterface
{
    protected Category $model;
    
    public function __construct(Category $model)
    {
        $this->model = $model;
    }

    public function getAllCategories(bool $useCache = true): Collection
    {
        if (!$useCache) {
            return $this->model->all();
        }

        return Cache::tags(['categories'])->remember(
            'categories.all',
            config('cache.categories.ttl'),
            fn() => $this->model->all()
        );
    }

    public function findById(int $id): Category
    {
        $cacheKey = "category.{$id}";
        
        $category = Cache::tags(['categories'])->remember(
            $cacheKey,
            config('cache.categories.ttl'),
            fn() => $this->model->find($id)
        );

        if (!$category) {
            throw new CategoryNotFoundException("Category with ID {$id} not found");
        }

        return $category;
    }

    public function create(array $data): Category
    {
        DB::beginTransaction();
        try {
            $category = $this->model->create($data);
            $this->clearCategoryCache();
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
            $category = $this->findById($id);
            $category->update($data);
            $this->clearCategoryCache();
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
            $category = $this->findById($id);
            $deleted = $category->delete();
            $this->clearCategoryCache();
            DB::commit();
            return $deleted;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    protected function clearCategoryCache(): void
    {
        Cache::tags(['categories'])->flush();
    }
}
