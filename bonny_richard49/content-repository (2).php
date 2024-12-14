<?php

namespace App\Core\CMS;

final class ContentRepository
{
    private ValidationService $validator;
    private SecurityService $security;
    private CacheManager $cache;
    private QueryBuilder $query;

    public function __construct(
        ValidationService $validator,
        SecurityService $security,
        CacheManager $cache,
        QueryBuilder $query
    ) {
        $this->validator = $validator;
        $this->security = $security;
        $this->cache = $cache;
        $this->query = $query;
    }

    public function find(int $id): ?Content
    {
        return $this->cache->remember("content:{$id}", function() use ($id) {
            return $this->query
                ->select('contents.*')
                ->where('id', $id)
                ->whereNull('deleted_at')
                ->first();
        });
    }

    public function findWithRelations(int $id, array $relations = []): ?Content
    {
        return $this->cache->remember("content:{$id}:relations", function() use ($id, $relations) {
            return $this->query
                ->select('contents.*')
                ->with($relations)
                ->where('id', $id)
                ->whereNull('deleted_at')
                ->first();
        });
    }

    public function create(array $data): Content
    {
        // Validate data structure
        $this->validator->validateContentData($data);
        
        // Create content
        $content = Content::create($data);
        
        // Clear cache
        $this->cache->invalidateGroup('content');
        
        return $content;
    }

    public function update(Content $content, array $data): bool
    {
        // Validate update data
        $this->validator->validateContentData($data);
        
        // Update content
        $updated = $content->update($data);
        
        // Clear cache
        $this->cache->invalidateGroup("content:{$content->id}");
        $this->cache->invalidateGroup('content');
        
        return $updated;
    }

    public function delete(Content $content): bool
    {
        // Soft delete
        $deleted = $content->delete();
        
        // Clear cache
        $this->cache->invalidateGroup("content:{$content->id}");
        $this->cache->invalidateGroup('content');
        
        return $deleted;
    }

    public function getPublished(array $criteria = [], array $relations = []): Collection
    {
        $cacheKey = "content:published:" . md5(serialize($criteria));
        
        return $this->cache->remember($cacheKey, function() use ($criteria, $relations) {
            $query = $this->query
                ->select('contents.*')
                ->whereNotNull('published_at')
                ->where('published_at', '<=', now())
                ->whereNull('deleted_at');
                
            // Apply criteria
            foreach ($criteria as $key => $value) {
                $query->where($key, $value);
            }
            
            // Load relations if specified
            if (!empty($relations)) {
                $query->with($relations);
            }
            
            return $query->get();
        });
    }

    public function getByType(string $type, array $criteria = [], array $relations = []): Collection
    {
        $cacheKey = "content:type:{$type}:" . md5(serialize($criteria));
        
        return $this->cache->remember($cacheKey, function() use ($type, $criteria, $relations) {
            $query = $this->query
                ->select('contents.*')
                ->where('type', $type)
                ->whereNull('deleted_at');
                
            // Apply additional criteria
            foreach ($criteria as $key => $value) {
                $query->where($key, $value);
            }
            
            // Load relations if specified
            if (!empty($relations)) {
                $query->with($relations);
            }
            
            return $query->get();
        });
    }
}
