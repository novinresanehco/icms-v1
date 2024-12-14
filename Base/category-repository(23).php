<?php

namespace App\Core\Repositories;

use App\Core\Repositories\Contracts\CategoryRepositoryInterface;
use App\Models\Category;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

class CategoryRepository extends BaseRepository implements CategoryRepositoryInterface
{
    public function __construct(Category $model)
    {
        parent::__construct($model);
    }

    public function getTree(): Collection
    {
        return Cache::tags(['categories'])->remember(
            'category_tree',
            now()->addDay(),
            fn () => $this->model->whereNull('parent_id')
                ->with('children')
                ->orderBy('order')
                ->get()
        );
    }

    public function findBySlug(string $slug): ?Category
    {
        return Cache::tags(['categories'])->remember(
            "category:slug:{$slug}",
            now()->addDay(),
            fn () => $this->model->where('slug', $slug)->first()
        );
    }

    public function updateOrder(array $order): bool
    {
        $success = true;

        foreach ($order as $id => $position) {
            $success = $success && $this->update($id, ['order' => $position]);
        }

        if ($success) {
            Cache::tags(['categories'])->flush();
        }

        return $success;
    }

    public function getChildren(int $parentId): Collection
    {
        return Cache::tags(['categories'])->remember(
            "category:children:{$parentId}",
            now()->addHours(6),
            fn () => $this->model->where('parent_id', $parentId)
                ->orderBy('order')
                ->get()
        );
    }

    public function getPath(int $categoryId): Collection
    {
        return Cache::tags(['categories'])->remember(
            "category:path:{$categoryId}",
            now()->addDay(),
            function () use ($categoryId) {
                $path = collect();
                $category = $this->find($categoryId);

                while ($category) {
                    $path->prepend($category);
                    $category = $category->parent;
                }

                return $path;
            }
        );
    }
}
