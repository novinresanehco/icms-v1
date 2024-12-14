<?php

namespace App\Repositories;

use App\Core\Repositories\BaseRepository;
use App\Models\{Category, Tag};
use App\Core\Contracts\{CategoryRepositoryInterface, TagRepositoryInterface};
use Illuminate\Database\Eloquent\Collection;
use App\Core\Exceptions\RepositoryException;

class CategoryRepository extends BaseRepository implements CategoryRepositoryInterface
{
    protected array $searchable = ['name', 'description', 'slug'];
    protected array $with = ['parent', 'children'];

    public function model(): string
    {
        return Category::class;
    }

    public function getTree(): Collection
    {
        $cacheKey = $this->getCacheKey(__FUNCTION__);
        
        return $this->remember($cacheKey, function () {
            $query = $this->model->with($this->with)
                ->whereNull('parent_id')
                ->orderBy('order');
                
            $this->performanceManager->monitorQueryPerformance();
            return $query->get();
        });
    }

    public function findBySlug(string $slug): ?Category
    {
        $cacheKey = $this->getCacheKey(__FUNCTION__, compact('slug'));
        
        return $this->remember($cacheKey, function () use ($slug) {
            $query = $this->model->with($this->with)
                ->where('slug', $slug);
                
            $this->performanceManager->monitorQueryPerformance();
            return $query->first();
        });
    }

    public function reorder(array $order): bool
    {
        try {
            $this->beginTransaction();

            foreach ($order as $position => $categoryId) {
                $this->update($categoryId, ['order' => $position]);
            }

            $this->commit();
            $this->clearCache();
            
            return true;
        } catch (\Exception $e) {
            $this->rollBack();
            throw new RepositoryException("Failed to reorder categories: {$e->getMessage()}");
        }
    }

    public function moveToParent(int $categoryId, ?int $parentId): bool
    {
        try {
            $category = $this->find($categoryId);
            
            if (!$category) {
                throw new RepositoryException("Category not found");
            }

            if ($parentId) {
                $parent = $this->find($parentId);
                if (!$parent) {
                    throw new RepositoryException("Parent category not found");
                }
            }

            $category->parent_id = $parentId;
            $category->save();
            
            $this->clearCache();
            
            return true;
        } catch (\Exception $e) {
            throw new RepositoryException("Failed to move category: {$e->getMessage()}");
        }
    }

    public function getWithContentCount(): Collection
    {
        $cacheKey = $this->getCacheKey(__FUNCTION__);
        
        return $this->remember($cacheKey, function () {
            $query = $this->model->withCount('contents')
                ->orderBy('contents_count', 'desc');
                
            $this->performanceManager->monitorQueryPerformance();
            return $query->get();
        });
    }
}

class TagRepository extends BaseRepository implements TagRepositoryInterface
{
    protected array $searchable = ['name', 'slug'];

    public function model(): string
    {
        return Tag::class;
    }

    public function findBySlug(string $slug): ?Tag
    {
        $cacheKey = $this->getCacheKey(__FUNCTION__, compact('slug'));
        
        return $this->remember($cacheKey, function () use ($slug) {
            $query = $this->model->where('slug', $slug);
            
            $this->performanceManager->monitorQueryPerformance();
            return $query->first();
        });
    }

    public function getPopular(int $limit = 10): Collection
    {
        $cacheKey = $this->getCacheKey(__FUNCTION__, compact('limit'));
        
        return $this->remember($cacheKey, function () use ($limit) {
            $query = $this->model->withCount('contents')
                ->orderBy('contents_count', 'desc')
                ->limit($limit);
                
            $this->performanceManager->monitorQueryPerformance();
            return $query->get();
        });
    }

    public function syncContentTags(int $contentId, array $tags): void
    {
        try {
            $this->beginTransaction();

            // Remove existing tags
            $this->model->whereHas('contents', function ($query) use ($contentId) {
                $query->where('content_id', $contentId);
            })->delete();

            // Create new tags
            foreach ($tags as $tagName) {
                $tag = $this->firstOrCreate(['name' => $tagName], [
                    'slug' => \Str::slug($tagName)
                ]);

                $tag->contents()->attach($contentId);
            }

            $this->commit();
            $this->clearCache();
        } catch (\Exception $e) {
            $this->rollBack();
            throw new RepositoryException("Failed to sync tags: {$e->getMessage()}");
        }
    }

    public function mergeTags(array $sourceTagIds, int $targetTagId): bool
    {
        try {
            $this->beginTransaction();

            $targetTag = $this->find($targetTagId);
            if (!$targetTag) {
                throw new RepositoryException("Target tag not found");
            }

            foreach ($sourceTagIds as $sourceTagId) {
                $sourceTag = $this->find($sourceTagId);
                if (!$sourceTag) {
                    continue;
                }

                // Move all content relationships to target tag
                $sourceTag->contents()->update(['tag_id' => $targetTagId]);
                $sourceTag->delete();
            }

            $this->commit();
            $this->clearCache();
            
            return true;
        } catch (\Exception $e) {
            $this->rollBack();
            throw new RepositoryException("Failed to merge tags: {$e->getMessage()}");
        }
    }

    public function firstOrCreate(array $attributes, array $values = []): Tag
    {
        $tag = $this->model->firstOrCreate($attributes, $values);
        $this->clearCache();
        return $tag;
    }
}
