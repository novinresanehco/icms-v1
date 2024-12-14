<?php

namespace App\Core\Repositories;

use App\Core\Models\Category;
use App\Core\Exceptions\CategoryNotFoundException;
use App\Core\Repositories\Contracts\CategoryRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class CategoryRepository implements CategoryRepositoryInterface
{
    public function __construct(
        private Category $model
    ) {}

    public function findById(int $id): ?Category
    {
        try {
            return $this->model->with(['parent', 'children'])->findOrFail($id);
        } catch (ModelNotFoundException) {
            throw new CategoryNotFoundException("Category with ID {$id} not found");
        }
    }

    public function findBySlug(string $slug): ?Category
    {
        try {
            return $this->model->with(['parent', 'children'])
                ->where('slug', $slug)
                ->firstOrFail();
        } catch (ModelNotFoundException) {
            throw new CategoryNotFoundException("Category with slug {$slug} not found");
        }
    }

    public function getTree(): Collection
    {
        return $this->model->whereNull('parent_id')
            ->with('children')
            ->orderBy('order')
            ->get();
    }

    public function getAllWithContent(): Collection
    {
        return $this->model->with(['content' => function($query) {
            $query->where('status', 'published')
                ->where('published_at', '<=', now())
                ->orderBy('published_at', 'desc');
        }])->get();
    }

    public function store(array $data): Category
    {
        if (isset($data['parent_id'])) {
            $this->verifyParentExists($data['parent_id']);
        }

        $maxOrder = $this->model
            ->where('parent_id', $data['parent_id'] ?? null)
            ->max('order');

        $data['order'] = $maxOrder ? $maxOrder + 1 : 0;

        return $this->model->create($data);
    }

    public function update(int $id, array $data): ?Category
    {
        $category = $this->findById($id);

        if (isset($data['parent_id'])) {
            $this->verifyParentExists($data['parent_id']);
            $this->verifyNotMovingToChild($category, $data['parent_id']);
        }

        $category->update($data);
        return $category->fresh(['parent', 'children']);
    }

    public function delete(int $id): bool
    {
        $category = $this->findById($id);

        if ($category->children()->exists()) {
            throw new \RuntimeException("Cannot delete category with children");
        }

        return (bool) $category->delete();
    }

    public function reorder(array $order): bool
    {
        foreach ($order as $position => $categoryId) {
            $this->model->where('id', $categoryId)->update(['order' => $position]);
        }
        return true;
    }

    public function moveToParent(int $id, ?int $parentId): ?Category
    {
        $category = $this->findById($id);

        if ($parentId) {
            $this->verifyParentExists($parentId);
            $this->verifyNotMovingToChild($category, $parentId);
        }

        $category->parent_id = $parentId;
        $category->save();

        return $category->fresh(['parent', 'children']);
    }

    private function verifyParentExists(int $parentId): void
    {
        if (!$this->model->where('id', $parentId)->exists()) {
            throw new CategoryNotFoundException("Parent category not found");
        }
    }

    private function verifyNotMovingToChild(Category $category, int $newParentId): void
    {
        if ($category->descendants->contains('id', $newParentId)) {
            throw new \RuntimeException("Cannot move category to its own descendant");
        }
    }
}
