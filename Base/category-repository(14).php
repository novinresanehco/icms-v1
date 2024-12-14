<?php

namespace App\Core\Repositories;

use App\Core\Models\Category;
use App\Core\Repositories\Contracts\CategoryRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Cache;

class CategoryRepository implements CategoryRepositoryInterface
{
    protected Category $model;
    protected const CACHE_PREFIX = 'category:';
    protected const CACHE_TTL = 3600; // 1 hour

    public function __construct(Category $model)
    {
        $this->model = $model;
    }

    public function findById(int $id): ?Category
    {
        return Cache::remember(
            self::CACHE_PREFIX . $id,
            self::CACHE_TTL,
            fn() => $this->model->find($id)
        );
    }

    public function create(array $data): Category
    {
        $category = $this->model->create($data);
        $this->clearCache();
        return $category;
    }

    public function update(Category $category, array $data): bool
    {
        $result = $category->update($data);
        if ($result) {
            $this->clearCache();
        }
        return $result;
    }

    public function delete(int $id): bool
    {
        $category = $this->findById($id);
        if (!$category) {
            throw new ModelNotFoundException("Category with ID {$id} not found");
        }

        if ($category->hasChildren()) {
            throw new \RuntimeException('Cannot delete category with children');
        }

        $result = $category->delete();
        if ($result) {
            $this->clearCache();
        }
        return $result;
    }

    public function getRoots(): Collection
    {
        return Cache::remember(
            self::CACHE_PREFIX . 'roots',
            self::CACHE_TTL,
            fn() => $this->model->roots()->orderBy('order')->get()
        );
    }

    public function getByType(string $type): Collection
    {
        return Cache::remember(
            self::CACHE_PREFIX . "type:{$type}",
            self::CACHE_TTL,
            fn() => $this->model->byType($type)->orderBy('order')->get()
        );
    }

    public function getWithChildren(int $id): Category
    {
        return Cache::remember(
            self::CACHE_PREFIX . "with_children:{$id}",
            self::CACHE_TTL,
            fn() => $this->model->with('children')->findOrFail($id)
        );
    }

    public function reorder(array $order): bool
    {
        try {
            \DB::beginTransaction();
            
            foreach ($order as $position => $categoryId) {
                $this->model->where('id', $categoryId)
                    ->update(['order' => $position]);
            }
            
            \DB::commit();
            $this->clearCache();
            return true;
            
        } catch (\Exception $e) {
            \DB::rollBack();
            throw $e;
        }
    }

    public function getTree(): Collection
    {
        return Cache::remember(
            self::CACHE_PREFIX . 'tree',
            self::CACHE_TTL,
            fn() => $this->model->with('children')
                ->whereNull('parent_id')
                ->orderBy('order')
                ->get()
        );
    }

    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return $this->model->orderBy('order')->paginate($perPage);
    }

    protected function clearCache(): void
    {
        Cache::tags(['categories'])->flush();
    }
}
