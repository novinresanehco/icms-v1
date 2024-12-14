<?php

namespace App\Core\Category\Repository;

use App\Core\Category\Models\Category;
use App\Core\Category\Events\CategoryCreated;
use App\Core\Category\Events\CategoryUpdated;
use App\Core\Category\Events\CategoryDeleted;
use App\Core\Category\Events\CategoryMoved;
use App\Core\Category\Exceptions\CategoryNotFoundException;
use App\Core\Shared\Repository\BaseRepository;
use App\Core\Shared\Cache\CacheManagerInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

class CategoryRepository extends BaseRepository implements CategoryRepositoryInterface
{
    protected const CACHE_KEY = 'categories';
    protected const CACHE_TTL = 3600; // 1 hour

    public function __construct(CacheManagerInterface $cache)
    {
        parent::__construct($cache);
        $this->setCacheKey(self::CACHE_KEY);
        $this->setCacheTtl(self::CACHE_TTL);
    }

    protected function getModelClass(): string
    {
        return Category::class;
    }

    public function findBySlug(string $slug): ?Category
    {
        return $this->cache->remember(
            $this->getCacheKey("slug:{$slug}"),
            fn() => $this->model->where('slug', $slug)
                               ->with(['parent', 'children'])
                               ->first()
        );
    }

    public function getRootCategories(): Collection
    {
        return $this->cache->remember(
            $this->getCacheKey('root'),
            fn() => $this->model->whereNull('parent_id')
                               ->orderBy('order')
                               ->get()
        );
    }

    public function getCategoryTree(?int $parentId = null): Collection
    {
        $cacheKey = $parentId 
            ? $this->getCacheKey("tree:{$parentId}")
            : $this->getCacheKey('tree');

        return $this->cache->remember($cacheKey, function() use ($parentId) {
            $query = $this->model->with(['children' => function($query) {
                $query->orderBy('order');
            }]);

            if ($parentId) {
                return $query->where('parent_id', $parentId)->get();
            }

            return $query->whereNull('parent_id')
                        ->orderBy('order')
                        ->get();
        });
    }

    public function getAncestors(int $categoryId): Collection
    {
        return $this->cache->remember(
            $this->getCacheKey("ancestors:{$categoryId}"),
            function() use ($categoryId) {
                $category = $this->findOrFail($categoryId);
                return $category->ancestors()->orderBy('depth')->get();
            }
        );
    }

    public function getDescendants(int $categoryId): Collection
    {
        return $this->cache->remember(
            $this->getCacheKey("descendants:{$categoryId}"),
            function() use ($categoryId) {
                $category = $this->findOrFail($categoryId);
                return $category->descendants()->orderBy('depth')->get();
            }
        );
    }

    public function getSiblings(int $categoryId): Collection
    {
        return $this->cache->remember(
            $this->getCacheKey("siblings:{$categoryId}"),
            function() use ($categoryId) {
                $category = $this->findOrFail($categoryId);
                return $this->model->where('parent_id', $category->parent_id)
                                 ->where('id', '!=', $categoryId)
                                 ->orderBy('order')
                                 ->get();
            }
        );
    }

    public function moveCategory(int $categoryId, ?int $newParentId): Category
    {
        DB::beginTransaction();
        try {
            $category = $this->findOrFail($categoryId);
            $oldParentId = $category->parent_id;

            // Update parent
            $category->parent_id = $newParentId;
            $category->save();

            // Recalculate order for old and new parent's children
            if ($oldParentId) {
                $this->reorderSiblings($oldParentId);
            }
            if ($newParentId) {
                $this->reorderSiblings($newParentId);
            }

            // Clear cache
            $this->clearCache();

            // Dispatch event
            Event::dispatch(new CategoryMoved($category, $oldParentId, $newParentId));

            DB::commit();
            return $category->fresh();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function getByOrder(): Collection
    {
        return $this->cache->remember(
            $this->getCacheKey('ordered'),
            fn() => $this->model->orderBy('order')->get()
        );
    }

    public function updateOrder(array $order): bool
    {
        DB::beginTransaction();
        try {
            foreach ($order as $id => $position) {
                $this->model->where('id', $id)->update(['order' => $position]);
            }

            // Clear cache
            $this->clearCache();

            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function getWithContentCount(): Collection
    {
        return $this->cache->remember(
            $this->getCacheKey('content_count'),
            fn() => $this->model->withCount('contents')->get()
        );
    }

    public function getActiveWithContent(): Collection
    {
        return $this->cache->remember(
            $this->getCacheKey('active_with_content'),
            fn() => $this->model->whereHas('contents', function($query) {
                $query->where('status', 'published');
            })->get()
        );
    }

    protected function reorderSiblings(int $parentId): void
    {
        $siblings = $this->model->where('parent_id', $parentId)
                               ->orderBy('order')
                               ->get();

        foreach ($siblings as $index => $sibling) {
            $sibling->update(['order' => $index + 1]);
        }
    }

    public function create(array $data): Category
    {
        DB::beginTransaction();
        try {
            // Set order if not provided
            if (!isset($data['order'])) {
                $data['order'] = $this->getNextOrder($data['parent_id'] ?? null);
            }

            $category = parent::create($data);

            // Clear cache
            $this->clearCache();

            // Dispatch event
            Event::dispatch(new CategoryCreated($category));

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
            $category = parent::update($id, $data);

            // Clear cache
            $this->clearCache();

            // Dispatch event
            Event::dispatch(new CategoryUpdated($category));

            DB::commit();
            return $category;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    protected function getNextOrder(?int $parentId): int
    {
        return $this->model->where('parent_id', $parentId)
                          ->max('order') + 1;
    }
}
