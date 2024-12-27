<?php

namespace App\Core\Tagging;

use App\Core\Security\SecurityContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TagManager implements TagManagerInterface
{
    private ValidationService $validator;
    private SecurityManager $security;
    private CacheManager $cache;
    private TagRepository $repository;

    public function __construct(
        ValidationService $validator,
        SecurityManager $security,
        CacheManager $cache,
        TagRepository $repository
    ) {
        $this->validator = $validator;
        $this->security = $security;
        $this->cache = $cache;
        $this->repository = $repository;
    }

    public function createTag(array $data, SecurityContext $context): Tag
    {
        DB::beginTransaction();
        
        try {
            $validated = $this->validator->validateCriticalData($data, [
                'name' => ['type' => 'string', 'required' => true],
                'type' => ['type' => 'string', 'required' => true],
                'metadata' => ['type' => 'array', 'required' => false]
            ]);

            $tag = $this->repository->create([
                'name' => $validated['name'],
                'type' => $validated['type'],
                'metadata' => $validated['metadata'] ?? [],
                'user_id' => $context->getUserId(),
                'created_at' => now()
            ]);

            $this->cache->tags(['tags'])->flush();
            
            DB::commit();
            return $tag;
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw new TagException('Failed to create tag: ' . $e->getMessage());
        }
    }

    public function attachTags(int $contentId, array $tagIds, SecurityContext $context): void
    {
        DB::beginTransaction();
        
        try {
            $content = Content::findOrFail($contentId);
            $this->security->validateAccess($content, $context);

            $content->tags()->sync($tagIds);
            
            $this->cache->tags([
                'tags', 
                "content:$contentId:tags"
            ])->flush();
            
            DB::commit();
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw new TagException('Failed to attach tags: ' . $e->getMessage());
        }
    }

    public function detachTags(int $contentId, array $tagIds, SecurityContext $context): void
    {
        DB::beginTransaction();
        
        try {
            $content = Content::findOrFail($contentId);
            $this->security->validateAccess($content, $context);

            $content->tags()->detach($tagIds);
            
            $this->cache->tags([
                'tags',
                "content:$contentId:tags"
            ])->flush();
            
            DB::commit();
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw new TagException('Failed to detach tags: ' . $e->getMessage());
        }
    }

    public function getContentTags(int $contentId): Collection
    {
        return $this->cache->tags(['tags', "content:$contentId:tags"])
            ->remember("content:$contentId:tags", 3600, function() use ($contentId) {
                return Content::findOrFail($contentId)
                    ->tags()
                    ->get();
            });
    }

    public function searchTags(string $query): Collection
    {
        return $this->repository->search