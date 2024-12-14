<?php

namespace App\Core\Repositories;

use App\Core\Models\Category;
use App\Core\Repositories\Contracts\CategoryRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class CategoryRepository implements CategoryRepositoryInterface
{
    public function __construct(
        private Category $model
    ) {}

    public function findById(int $id): ?Category
    {
        return $this->model
            ->with(['parent', 'children'])
            ->withCount('posts')
            ->find($id);
    }

    public function findBySlug(string $slug): ?Category
    {
        return $this->model
            ->with(['parent', 'children'])
            ->withCount('posts')
            ->where('slug', $slug)
            ->first();
    }

    public function getAll(): Collection
    {
        return $this->model
            ->withCount('posts')
            ->orderBy('order')
            ->get();
    }

    public function getAllPaginated(int $perPage = 15): LengthAwarePaginator
    {
        return $this->model
            ->withCount('posts')
            ->orderBy('order')
            ->paginate($perPage);
    }

    public function getParentCategories(): Collection
    {
        return $this->model
            ->whereNull('parent_id')
            ->withCount('posts')
            ->orderBy('order')
            ->get();
    }

    public function getPopular(int $limit = 10): Collection
    {
        return $this->model
            ->withCount('posts')
            ->orderByDesc('posts_count')
            ->limit($limit)
            ->get();
    }

    public function getWithChildren(): Collection
    {
        return $this->model
            ->with(['children' => function ($query) {
                $query->withCount('posts')->orderBy('order');
            }])
            ->whereNull('parent_id')
            ->withCount('posts')
            ->orderBy('order')
            ->get();
    }

    public function store(array $data): Category
    {
        $data['slug'] = Str::slug($data['name']);
        return $this->model->create($data);
    }

    public function update(int $id, array $data): Category
    {
        $category = $this->model->findOrFail($id);
        
        if (isset($data['name'])) {
            $data['slug'] = Str::slug($data['name']);
        }
        
        $category->update($data);
        return $category->fresh();
    }

    public function delete(int $id): bool
    {
        return $this->model->findOrFail($id)->delete();
    }

    public function restore(int $id): bool
    {
        return $this->model->withTrashed()->findOrFail($id)->restore();
    }

    public function reorder(array $data): bool
    {
        try {
            foreach ($data as $item) {
                $this->model->find($item['id'])->update([
                    'parent_id' => $item['parent_id'] ?? null,
                    'order' => $item['order']
                ]);
            }
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
