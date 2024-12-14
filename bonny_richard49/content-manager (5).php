<?php

namespace App\Core\Content;

use App\Core\Security\SecurityContext;
use App\Core\Security\CoreSecurityManager;
use App\Core\Cache\CacheManager;
use Illuminate\Support\Facades\DB;

class ContentManager implements ContentManagerInterface 
{
    private CoreSecurityManager $security;
    private Repository $repository;
    private CacheManager $cache;
    private ValidationService $validator;

    public function __construct(
        CoreSecurityManager $security,
        Repository $repository, 
        CacheManager $cache,
        ValidationService $validator
    ) {
        $this->security = $security;
        $this->repository = $repository;
        $this->cache = $cache;
        $this->validator = $validator;
    }

    public function create(array $data, SecurityContext $context): Content
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->doCreate($data),
            $context
        );
    }

    public function update(int $id, array $data, SecurityContext $context): Content
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->doUpdate($id, $data),
            $context
        );
    }

    public function delete(int $id, SecurityContext $context): bool
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->doDelete($id),
            $context
        );
    }

    private function doCreate(array $data): Content
    {
        // Validate input
        $validated = $this->validator->validate($data, [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'status' => 'required|in:draft,published'
        ]);

        // Create with audit trail
        $content = DB::transaction(function() use ($validated) {
            $content = $this->repository->create($validated);
            
            // Cache management
            $this->cache->invalidate(['content', 'content:'.$content->id]);
            $this->cache->tags(['content'])->put(
                'content:'.$content->id, 
                $content, 
                config('cache.ttl')
            );
            
            return $content;
        });

        return $content;
    }

    private function doUpdate(int $id, array $data): Content
    {
        $content = $this->repository->findOrFail($id);
        
        // Validate changes
        $validated = $this->validator->validate($data, [
            'title' => 'sometimes|string|max:255',
            'content' => 'sometimes|string',
            'status' => 'sometimes|in:draft,published'
        ]);

        // Update with version control
        DB::transaction(function() use ($content, $validated) {
            // Archive current version
            $this->repository->archiveVersion($content);
            
            // Update content
            $content->update($validated);
            
            // Cache management 
            $this->cache->invalidate(['content', 'content:'.$content->id]);
            $this->cache->tags(['content'])->put(
                'content:'.$content->id,
                $content,
                config('cache.ttl')
            );
        });

        return $content;
    }

    private function doDelete(int $id): bool
    {
        $content = $this->repository->findOrFail($id);
        
        return DB::transaction(function() use ($content) {
            // Archive for audit trail
            $this->repository->archiveVersion($content);
            
            // Soft delete
            $result = $content->delete();
            
            // Cache cleanup
            $this->cache->invalidate(['content', 'content:'.$content->id]);
            
            return $result;
        });
    }
}
