<?php

namespace App\Repositories;

use App\Models\Content;
use App\Repositories\Contracts\ContentRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;

class ContentRepository extends BaseRepository implements ContentRepositoryInterface
{
    protected array $searchableFields = ['title', 'content', 'slug', 'meta_description'];
    protected array $filterableFields = ['status', 'type', 'category_id', 'author_id'];
    
    /**
     * Create a new content entry with versioning
     *
     * @param array $data Content data
     * @return Content
     */
    public function create(array $data): Content
    {
        $content = parent::create($data);
        
        // Create initial version
        $this->createVersion($content, $data);
        
        // Clear relevant caches
        $this->clearContentCaches($content);
        
        return $content;
    }
    
    /**
     * Update content with version tracking
     *
     * @param int $id Content ID
     * @param array $data Updated data
     * @return Content
     */
    public function update(int $id, array $data): Content
    {
        $content = parent::update($id, $data);
        
        // Create new version
        $this->createVersion($content, $data);
        
        // Clear caches
        $this->clearContentCaches($content);
        
        return $content;
    }
    
    /**
     * Get published content with caching
     *
     * @param array $relations Relations to eager load
     * @return Collection
     */
    public function getPublished(array $relations = []): Collection 
    {
        $cacheKey = 'content.published.' . md5(serialize($relations));
        
        return Cache::tags(['content'])->remember($cacheKey, 3600, function() use ($relations) {
            return $this->model
                ->published()
                ->with($relations)
                ->orderBy('published_at', 'desc')
                ->get();
        });
    }
    
    /**
     * Get paginated content list
     *
     * @param int $perPage Items per page
     * @param array $filters Filter criteria
     * @param array $relations Relations to eager load
     * @return LengthAwarePaginator
     */
    public function getPaginated(
        int $perPage = 15, 
        array $filters = [], 
        array $relations = []
    ): LengthAwarePaginator {
        $query = $this->model->newQuery();
        
        // Apply filters
        foreach ($filters as $field => $value) {
            if (in_array($field, $this->filterableFields)) {
                $query->where($field, $value);
            }
        }
        
        // Add relations
        if (!empty($relations)) {
            $query->with($relations);
        }
        
        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }
    
    /**
     * Search content by criteria
     *
     * @param string $term Search term
     * @param array $filters Additional filters
     * @return Collection
     */
    public function search(string $term, array $filters = []): Collection
    {
        $query = $this->model->newQuery();
        
        // Apply search term to searchable fields
        $query->where(function($q) use ($term) {
            foreach ($this->searchableFields as $field) {
                $q->orWhere($field, 'LIKE', "%{$term}%");
            }
        });
        
        // Apply additional filters
        foreach ($filters as $field => $value) {
            if (in_array($field, $this->filterableFields)) {
                $query->where($field, $value);
            }
        }
        
        return $query->get();
    }
    
    /**
     * Get content by slug with caching
     *
     * @param string $slug Content slug
     * @param array $relations Relations to eager load
     * @return Content|null
     */
    public function findBySlug(string $slug, array $relations = []): ?Content
    {
        $cacheKey = 'content.slug.' . $slug . '.' . md5(serialize($relations));
        
        return Cache::tags(['content'])->remember($cacheKey, 3600, function() use ($slug, $relations) {
            return $this->model
                ->where('slug', $slug)
                ->with($relations)
                ->first();
        });
    }
    
    /**
     * Create a new content version
     *
     * @param Content $content Content model
     * @param array $data Version data
     * @return void
     */
    protected function createVersion(Content $content, array $data): void
    {
        $content->versions()->create([
            'content' => $data['content'] ?? '',
            'title' => $data['title'] ?? '',
            'metadata' => [
                'editor_id' => auth()->id(),
                'editor_ip' => request()->ip(),
                'changes' => $this->calculateChanges($content, $data)
            ]
        ]);
    }
    
    /**
     * Clear content related caches
     *
     * @param Content $content Content model
     * @return void
     */
    protected function clearContentCaches(Content $content): void
    {
        Cache::tags(['content'])->flush();
    }
    
    /**
     * Calculate changes between versions
     *
     * @param Content $content Current content
     * @param array $newData New content data
     * @return array Changes array
     */
    protected function calculateChanges(Content $content, array $newData): array
    {
        $changes = [];
        
        foreach ($newData as $field => $value) {
            if ($content->$field !== $value) {
                $changes[$field] = [
                    'old' => $content->$field,
                    'new' => $value
                ];
            }
        }
        
        return $changes;
    }
}
