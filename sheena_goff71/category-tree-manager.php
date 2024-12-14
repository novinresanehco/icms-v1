<?php

namespace App\Core\Category\Services;

use App\Core\Category\Models\Category;
use Illuminate\Support\Collection;

class CategoryTreeManager
{
    public function moveNode(Category $category, ?int $parentId, int $position = 0): void
    {
        $category->parent_id = $parentId;
        
        if ($position > 0) {
            $siblings = Category::where('parent_id', $parentId)
                ->where('id', '!=', $category->id)
                ->orderBy('sort_order')
                ->get();

            $this->updateNodePositions($siblings, $category, $position);
        }

        $category->save();
        $this->rebuildTree();
    }

    public function reorderNodes(array $order): array
    {
        $results = [];
        $position = 0;

        foreach ($order as $item) {
            try {
                $category = Category::findOrFail($item['id']);
                $category->parent_id = $item['parent_id'] ?? null;
                $category->sort_order = $position++;
                $category->save();

                if (!empty($item['children'])) {
                    $results = array_merge(
                        $results,
                        $this->reorderChildNodes($item['children'], $category->id)
                    );
                }

                $results[$item['id']] = [
                    'success' => true,
                    'position' => $category->sort_order
                ];
            } catch (\Exception $e) {
                $results[$item['id']] = [
                    'success' => false,
                    'error' => $e->getMessage()
                ];
            }
        }

        $this->rebuildTree();
        return $results;
    }

    protected function reorderChildNodes(array $children, int $parentId): array
    {
        $results = [];
        $position = 0;

        foreach ($children as $child) {
            try {
                $category = Category::findOrFail($child['id']);
                $category->parent_id = $parentId;
                $category->sort_order = $position++;
                $category->save();

                if (!empty($child['children'])) {
                    $results = array_merge(
                        $results,
                        $this->reorderChildNodes($child['children'], $category->id)
                    );
                }

                $results[$child['id']] = [
                    'success' => true,
                    'position' => $category->sort_order
                ];
            } catch (\Exception $e) {
                $results[$child['id']] = [
                    'success' => false,
                    'error' => $e->getMessage()
                ];
            }
        }

        return $results;
    }

    protected function updateNodePositions(Collection $siblings, Category $category, int $position): void
    {
        if ($position >= $siblings->count()) {
            $category->sort_order = ($siblings->last()->sort_order ?? 0) + 1;
            return;
        }

        $currentPosition = 0;
        foreach ($siblings as $sibling) {
            if ($currentPosition === $position) {
                $currentPosition++;
            }
            $sibling->sort_order = $currentPosition++;
            $sibling->save();
        }

        $category->sort_order = $position;
    }

    protected function rebuildTree(): void
    {
        $categories = Category::orderBy('sort_order')->get();
        $tree = [];
        $level = 0;

        foreach ($categories as $category) {
            $category->level = $this->calculateLevel($category);
            $category->path = $this->calculatePath($category);
            $category->save();
        }
    }

    protected function calculateLevel(Category $category): int
    {
        $level = 0;
        $parent = $category->parent;

        while ($parent) {
            $level++;
            $parent = $parent->parent;
        }

        return $level;
    }

    protected function calculatePath(Category $category): string
    {
        $path = [$category->id];
        $parent = $category->parent;

        while ($parent) {
            array_unshift($path, $parent->id);
            $parent = $parent->parent;
        }

        return implode('/', $path);
    }
}
