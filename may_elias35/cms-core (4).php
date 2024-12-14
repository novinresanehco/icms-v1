<?php

namespace App\Core\CMS;

use App\Core\Security\SecurityCore;
use App\Core\Exceptions\CMSException;
use Illuminate\Support\Facades\Cache;

final class CMSCore 
{
    private SecurityCore $security;
    private ContentRepository $content;
    private CacheService $cache;
    private ValidationService $validator;

    public function __construct(
        SecurityCore $security,
        ContentRepository $content,
        CacheService $cache,
        ValidationService $validator
    ) {
        $this->security = $security;
        $this->content = $content;
        $this->cache = $cache;
        $this->validator = $validator;
    }

    public function createContent(array $data, array $context): Content 
    {
        return $this->security->executeSecureOperation(
            fn() => $this->executeContentCreation($data),
            $context
        );
    }

    public function updateContent(int $id, array $data, array $context): Content 
    {
        return $this->security->executeSecureOperation(
            fn() => $this->executeContentUpdate($id, $data),
            $context
        );
    }

    public function deleteContent(int $id, array $context): bool 
    {
        return $this->security->executeSecureOperation(
            fn() => $this->executeContentDeletion($id),
            $context
        );
    }

    private function executeContentCreation(array $data): Content 
    {
        $validated = $this->validator->validate($data, [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'status' => 'required|in:draft,published'
        ]);

        $content = $this->content->create($validated);
        $this->cache->invalidateContentCache();
        
        return $content;
    }

    private function executeContentUpdate(int $id, array $data): Content 
    {
        $validated = $this->validator->validate($data, [
            'title' => 'string|max:255',
            'content' => 'string',
            'status' => 'in:draft,published'
        ]);

        $content = $this->content->update($id, $validated);
        $this->cache->invalidateContentCache($id);
        
        return $content;
    }

    private function executeContentDeletion(int $id): bool 
    {
        $result = $this->content->delete($id);
        $this->cache->invalidateContentCache($id);
        
        return $result;
    }
}
