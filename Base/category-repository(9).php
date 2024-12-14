<?php

namespace App\Repositories;

use App\Models\Category;
use App\Repositories\Contracts\CategoryRepositoryInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class CategoryRepository extends BaseRepository implements CategoryRepositoryInterface
{
    protected function getModel(): Model
    {
        return new Category();
    }

    public function findBySlug(string $slug): ?Category
    {
        return $this->model->where('slug', $slug)->first();
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
        $category = $this->model->find($categoryId);
        if (!$category) {
            return collect();
        }

        return $category->ancestors()->orderBy('depth')->get();
    }

    public function createWithParent(array $data, ?int $parentId = null): Category
    {
        $data['slug'] = $data['slug'] ?? Str::slug($data['name']);
        $data['parent_id'] = $parentId;
        
        if ($parentId) {
            $parent = $this->model->findOrFail($parentId);
            $data['depth'] = $parent->depth + 1;
            $data['path'] = $parent->path . '/' . $data['slug'];
        } else {
            $data['depth'] = 0;
            $data['path'] = $data['slug'];
        }
        
        $data['order'] = $this->getNextOrder($parentId);
        
        return $this->model->create($data);
    }

    public function updateWithParent(int $id, array $data, ?int $parentId = null): bool
    {
        $category = $this->model->findOrFail($id);
        
        if (isset($data['name']) && (!isset($data['slug']) || empty($data['slug']))) {
            $data['slug'] = Str::slug($data['name']);
        }
        
        if ($parentId !== null && $parentId !== $category->parent_id) {
            if ($this->wouldCreateLoop($id, $parentId)) {
                throw new \RuntimeException('Moving category would create a loop');
            }
            
            $data['parent_id'] = $parentId;
            $this->updateDepthAndPath($category, $parentId);
        }
        
        return $category->update($data);
    }

    public function moveCategory(int $categoryId, ?int $newParentId): bool
    {
        $category = $this->model->findOrFail($categoryId);
        
        if ($this->wouldCreateLoop($categoryId, $newParentId)) {
            throw new \RuntimeException('Moving category would create a loop');
        }
        
        $oldParentId = $category->parent_id;
        $this->updateDepthAndPath($category, $newParentId);
        
        $category->parent_id = $newParentId;
        $category->order = $this->getNextOrder($newParentId);
        
        if ($category->save()) {
            $this->reorderSiblings($oldParentId);
            return true;
        }
        
        return false;
    }

    public function getDescendants(int $categoryId): Collection
    {
        $category = $this->model->findOrFail($categoryId);
        return $category->descendants()->orderBy('depth')->get();
    }

    public function getAncestors(int $categoryId): Collection
    {
        $category = $this->model->findOrFail($categoryId);
        return $category->ancestors()->orderBy('depth')->get();
    }

    public function getSiblings(int $categoryId): Collection
    {
        $category = $this->model->findOrFail($categoryId);
        return $this->model->where('parent_id', $category->parent_id)
            ->where('id', '!=', $categoryId)
            ->orderBy('order')
            ->get();
    }

    public function reorder(array $order): bool
    {
        \DB::beginTransaction();
        
        try {
            foreach ($order as $position => $categoryId) {
                $this->model->where('id', $categoryId)
                    ->update(['order' => $position]);
            }
            
            \DB::commit();
            return true;
        } catch (\Exception $e) {
            \DB::rollBack();
            return false;
        }
    }

    protected function getNextOrder(?int $parentId): int
    {
        return $this->model->where('parent_id', $parentId)->max('order') + 1;
    }

    protected function wouldCreateLoop(int $categoryId, ?int $newParentId): bool
    {
        if ($newParentId === null) {
            return false;
        }

        $descendantIds = $this->getDescendants($categoryId)->pluck('id')->toArray();
        return in_array($newParentId, $descendantIds);
    }

    protected function updateDepthAndPath(Category $category, ?int $newParentId): void
    {
        if ($newParentId === null) {
            $category->depth = 0;
            $category->path = $category->slug;
        } else {
            $parent = $this->model->findOrFail($newParentId);
            $category->depth = $parent->depth + 1;
            $category->path = $parent->path . '/' . $category->slug;
        }

        foreach ($category->descendants as $descendant) {
            $descendant->depth = $descendant->ancestors()->count();
            $descendant->path = implode('/', $descendant->ancestors()->pluck('slug')->push($descendant->slug)->toArray());
            $descendant->save();
        }
    }

    protected function reorderSiblings(?int $parentId): void
    {
        $siblings = $this->model->where('parent_id', $parentId)
            ->orderBy('order')
            ->get();

        $order = 0;
        foreach ($siblings as $sibling) {
            $sibling->update(['order' => $order++]);
        }
    }
}
