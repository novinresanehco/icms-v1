<?php

namespace App\Core\Repositories;

/**
 * Base Repository Interface
 * Defines standard contract for all repositories
 */
interface RepositoryInterface
{
    public function find($id);
    public function findWhere(array $criteria);
    public function create(array $attributes);
    public function update($id, array $attributes);
    public function delete($id);
}

/**
 * Abstract Base Repository
 * Provides common repository functionality
 */
abstract class BaseRepository implements RepositoryInterface
{
    protected $model;
    protected $cache;
    protected $validator;

    public function __construct(
        protected Model $modelInstance,
        protected CacheManager $cacheManager,
        protected ValidatorInterface $validatorInstance
    ) {
        $this->model = $modelInstance;
        $this->cache = $cacheManager;
        $this->validator = $validatorInstance;
    }

    public function find($id)
    {
        return $this->cache->tags([$this->getCacheTag()])->remember(
            $this->getCacheKey($id),
            3600,
            fn() => $this->model->find($id)
        );
    }

    public function findWhere(array $criteria)
    {
        return $this->model->where($criteria)->get();
    }

    public function create(array $attributes)
    {
        $this->validator->validate($attributes, $this->getCreateRules());
        
        $model = $this->model->create($attributes);
        $this->clearCache();
        
        return $model;
    }

    public function update($id, array $attributes)
    {
        $this->validator->validate($attributes, $this->getUpdateRules($id));
        
        $model = $this->model->findOrFail($id);
        $model->update($attributes);
        
        $this->clearCache($id);
        
        return $model;
    }

    public function delete($id)
    {
        $model = $this->model->findOrFail($id);
        $result = $model->delete();
        
        $this->clearCache($id);
        
        return $result;
    }

    protected function clearCache($id = null)
    {
        if ($id) {
            $this->cache->forget($this->getCacheKey($id));
        }
        
        $this->cache->tags([$this->getCacheTag()])->flush();
    }

    abstract protected function getCacheTag(): string;
    abstract protected function getCreateRules(): array;
    abstract protected function getUpdateRules($id): array;
    
    protected function getCacheKey($id): string
    {
        return sprintf('%s.%s', $this->getCacheTag(), $id);
    }
}

/**
 * Content Repository
 * Handles all content-related database operations
 */
class ContentRepository extends BaseRepository
{
    protected function getCacheTag(): string
    {
        return 'content';
    }

    protected function getCreateRules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'slug' => 'required|string|unique:contents,slug',
            'content' => 'required|string',
            'status' => 'required|in:draft,published',
            'author_id' => 'required|exists:users,id',
            'category_id' => 'required|exists:categories,id'
        ];
    }

    protected function getUpdateRules($id): array
    {
        return [
            'title' => 'string|max:255',
            'slug' => "string|unique:contents,slug,{$id}",
            'content' => 'string',
            'status' => 'in:draft,published',
            'category_id' => 'exists:categories,id'
        ];
    }

    public function getPublished()
    {
        return $this->cache->tags([$this->getCacheTag(), 'published'])
            ->remember('content.published', 3600, function() {
                return $this->model
                    ->where('status', 'published')
                    ->orderBy('published_at', 'desc')
                    ->get();
            });
    }

    public function findBySlug($slug)
    {
        return $this->cache->tags([$this->getCacheTag()])
            ->remember("content.slug.{$slug}", 3600, function() use ($slug) {
                return $this->model->where('slug', $slug)->first();
            });
    }
}

/**
 * Category Repository
 * Handles all category-related database operations
 */
class CategoryRepository extends BaseRepository
{
    protected function getCacheTag(): string
    {
        return 'category';
    }

    protected function getCreateRules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'slug' => 'required|string|unique:categories,slug',
            'description' => 'nullable|string',
            'parent_id' => 'nullable|exists:categories,id'
        ];
    }

    protected function getUpdateRules($id): array
    {
        return [
            'name' => 'string|max:255',
            'slug' => "string|unique:categories,slug,{$id}",
            'description' => 'nullable|string',
            'parent_id' => 'nullable|exists:categories,id'
        ];
    }

    public function getHierarchy()
    {
        return $this->cache->tags([$this->getCacheTag(), 'hierarchy'])
            ->remember('category.hierarchy', 3600, function() {
                return $this->model
                    ->whereNull('parent_id')
                    ->with('children')
                    ->get();
            });
    }
}
