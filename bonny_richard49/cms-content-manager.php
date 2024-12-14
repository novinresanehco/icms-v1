<?php

namespace App\Core\Content;

use App\Core\Security\SecurityManager;
use App\Core\Cache\CacheManager;
use App\Core\Exceptions\ContentException;
use Illuminate\Support\Facades\DB;

class ContentManager
{
    private SecurityManager $security;
    private CacheManager $cache;
    private ContentRepository $repository;
    private ValidationService $validator;

    public function __construct(
        SecurityManager $security,
        CacheManager $cache,
        ContentRepository $repository,
        ValidationService $validator
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->repository = $repository;
        $this->validator = $validator;
    }

    public function createContent(array $data, array $context): Content
    {
        return $this->security->executeCriticalOperation(
            function() use ($data, $context) {
                $validatedData = $this->validator->validateContent($data);
                
                $content = $this->repository->create($validatedData);
                
                $this->cache->invalidateContentCache($content->id);
                
                return $content;
            },
            $context
        );
    }

    public function updateContent(int $id, array $data, array $context): Content
    {
        return $this->security->executeCriticalOperation(
            function() use ($id, $data, $context) {
                $validatedData = $this->validator->validateContent($data);
                
                $content = $this->repository->update($id, $validatedData);
                
                $this->cache->invalidateContentCache($id);
                
                return $content;
            },
            $context
        );
    }

    public function deleteContent(int $id, array $context): bool
    {
        return $this->security->executeCriticalOperation(
            function() use ($id, $context) {
                $result = $this->repository->delete($id);
                
                if ($result) {
                    $this->cache->invalidateContentCache($id);
                }
                
                return $result;
            },
            $context
        );
    }

    public function getContent(int $id, array $context): ?Content
    {
        return $this->cache->remember(
            "content.{$id}",
            function() use ($id, $context) {
                return $this->repository->find($id);
            },
            3600
        );
    }
}

class ContentRepository
{
    public function create(array $data): Content
    {
        return DB::transaction(function() use ($data) {
            $content = Content::create($data);
            
            if (isset($data['meta'])) {
                $content->meta()->create($data['meta']);
            }
            
            return $content->fresh();
        });
    }

    public function update(int $id, array $data): Content
    {
        return DB::transaction(function() use ($id, $data) {
            $content = Content::findOrFail($id);
            
            $content->update($data);
            
            if (isset($data['meta'])) {
                $content->meta()->update($data['meta']);
            }
            
            return $content->fresh();
        });
    }

    public function delete(int $id): bool
    {
        return DB::transaction(function() use ($id) {
            $content = Content::findOrFail($id);
            
            $content->meta()->delete();
            return $content->delete();
        });
    }

    public function find(int $id): ?Content
    {
        return Content::with('meta')->find($id);
    }
}
