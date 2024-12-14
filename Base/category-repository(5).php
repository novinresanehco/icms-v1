<?php

namespace App\Repositories;

use App\Models\Category;
use App\Repositories\Contracts\CategoryRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Database\Eloquent\Builder;

class CategoryRepository extends BaseRepository implements CategoryRepositoryInterface
{
    protected array $searchableFields = ['name', 'slug', 'description'];
    protected array $filterableFields = ['parent_id', 'status', 'type'];
    protected array $relationships = ['meta' => 'update'];

    public function __construct(Category $model)
    {
        parent::__construct($model);
    }

    public function getTree(): Collection
    {
        try {
            return Cache::remember(
                $this->getCacheKey('tree'),
                $this->cacheTTL,
                fn() => $this->model->whereNull('parent_id')
                    ->with(['children' => function ($query) {
                        $query->orderBy('order')->with('children');
                    }])
                    ->orderBy('order')
                    ->get()
            );
        } catch (\Exception $e) {
            Log::error('Failed to get category tree: ' . $e->getMessage());
            return new Collection();
        }
    }

    public function moveNode(int $id, ?int $parentId, int $order): bool
    {
        try {
            DB::beginTransaction();
            
            $node = $this->model->findOrFail($id);
            $node->parent_id = $parentId;
            $node->order = $order;
            $node->save();
            
            // Reorder siblings
            $this->reorderSiblings($parentId);
            
            DB::commit();
            $this->clearModelCache();
            
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to move category node: ' . $e->getMessage());
            return false;
        }
    }

    public function getByType(string $type): Collection
    {
        try {
            return Cache::remember(
                $this->getCacheKey("type.{$type}"),
                $this->cacheTTL,
                fn() => $this->model->where('type', $type)
                    ->orderBy('order')
                    ->get()
            );
        } catch (\Exception $e) {
            Log::error('Failed to get categories by type: ' . $e->getMessage());
            return new Collection();
        }
    }

    public function getActive(): Collection
    {
        try {
            return Cache::remember(
                $this->getCacheKey('active'),
                $this->cacheTTL,
                fn() => $this->model->where('status', 'active')
                    ->orderBy('order')
                    ->get()
            );
        } catch (\Exception $e) {
            Log::error('Failed to get active categories: ' . $e->getMessage());
            return new Collection();
        }
    }

    protected function reorderSiblings(?int $parentId): void
    {
        $siblings = $this->model->where('parent_id', $parentId)
            ->orderBy('order')
            ->get();

        foreach ($siblings as $index => $sibling) {
            $sibling->order = $index + 1;
            $sibling->save();
        }
    }
}
