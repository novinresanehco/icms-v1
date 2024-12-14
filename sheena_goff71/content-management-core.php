<?php

namespace App\Core\Content;

use App\Core\Security\SecurityContext;
use App\Core\Security\CoreSecurityManager;
use App\Core\Services\ValidationService;
use App\Core\Cache\CacheManager;
use Illuminate\Support\Facades\DB;

class ContentManager implements ContentManagementInterface 
{
    private CoreSecurityManager $security;
    private ValidationService $validator;
    private CacheManager $cache;
    private ContentRepository $repository;
    private MediaManager $mediaManager;
    private VersionManager $versionManager;

    public function __construct(
        CoreSecurityManager $security,
        ValidationService $validator,
        CacheManager $cache,
        ContentRepository $repository,
        MediaManager $mediaManager,
        VersionManager $versionManager
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->cache = $cache;
        $this->repository = $repository;
        $this->mediaManager = $mediaManager;
        $this->versionManager = $versionManager;
    }

    public function create(array $data, SecurityContext $context): Content 
    {
        return $this->security->executeCriticalOperation(
            new ContentOperation('create', $data),
            $context,
            function() use ($data) {
                $validated = $this->validator->validate($data, $this->getCreateRules());
                
                $content = $this->repository->create($validated);
                
                if (!empty($validated['media'])) {
                    $this->mediaManager->attachMedia($content->id, $validated['media']);
                }
                
                $this->versionManager->createInitialVersion($content);
                $this->cache->invalidateContentCache($content->id);
                
                return $content;
            }
        );
    }

    public function update(int $id, array $data, SecurityContext $context): Content 
    {
        return $this->security->executeCriticalOperation(
            new ContentOperation('update', $data, $id),
            $context,
            function() use ($id, $data) {
                $validated = $this->validator->validate($data, $this->getUpdateRules());
                
                $content = $this->repository->findOrFail($id);
                $this->versionManager->createVersion($content);
                
                $updated = $this->repository->update($id, $validated);
                
                if (isset($validated['media'])) {
                    $this->mediaManager->syncMedia($id, $validated['media']);
                }
                
                $this->cache->invalidateContentCache($id);
                
                return $updated;
            }
        );
    }

    public function publish(int $id, SecurityContext $context): bool 
    {
        return $this->security->executeCriticalOperation(
            new ContentOperation('publish', [], $id),
            $context,
            function() use ($id) {
                $content = $this->repository->findOrFail($id);
                
                if (!$this->validator->validatePublishState($content)) {
                    throw new ContentValidationException('Content not ready for publishing');
                }
                
                $this->repository->updateStatus($id, ContentStatus::PUBLISHED);
                $this->versionManager->tagVersion($content, 'published');
                $this->cache->invalidateContentCache($id);
                
                return true;
            }
        );
    }

    public function delete(int $id, SecurityContext $context): bool 
    {
        return $this->security->executeCriticalOperation(
            new ContentOperation('delete', [], $id),
            $context,
            function() use ($id) {
                $content = $this->repository->findOrFail($id);
                
                $this->mediaManager->detachAllMedia($id);
                $this->versionManager->archiveVersions($id);
                $this->repository->delete($id);
                $this->cache->invalidateContentCache($id);
                
                return true;
            }
        );
    }

    public function getById(int $id, SecurityContext $context): ?Content 
    {
        return $this->cache->remember(
            "content.{$id}",
            function() use ($id, $context) {
                return $this->security->executeCriticalOperation(
                    new ContentOperation('read', [], $id),
                    $context,
                    fn() => $this->repository->findWithMedia($id)
                );
            }
        );
    }

    public function search(array $criteria, SecurityContext $context): ContentCollection 
    {
        $validated = $this->validator->validate($criteria, $this->getSearchRules());
        
        return $this->security->executeCriticalOperation(
            new ContentOperation('search', $validated),
            $context,
            fn() => $this->repository->search($validated)
        );
    }

    public function restore(int $id, int $versionId, SecurityContext $context): Content 
    {
        return $this->security->executeCriticalOperation(
            new ContentOperation('restore', ['version_id' => $versionId], $id),
            $context,
            function() use ($id, $versionId) {
                $version = $this->versionManager->getVersion($id, $versionId);
                $restored = $this->repository->restore($id, $version->getData());
                
                $this->versionManager->createVersion($restored, 'restored');
                $this->cache->invalidateContentCache($id);
                
                return $restored;
            }
        );
    }

    private function getCreateRules(): array 
    {
        return [
            'title' => 'required|string|max:200',
            'content' => 'required|string',
            'status' => 'required|in:draft,published',
            'media.*' => 'exists:media,id',
            'meta' => 'array'
        ];
    }

    private function getUpdateRules(): array 
    {
        return [
            'title' => 'string|max:200',
            'content' => 'string',
            'status' => 'in:draft,published',
            'media.*' => 'exists:media,id',
            'meta' => 'array'
        ];
    }

    private function getSearchRules(): array 
    {
        return [
            'status' => 'in:draft,published',
            'title' => 'string|max:200',
            'from_date' => 'date',
            'to_date' => 'date|after:from_date',
            'tags' => 'array',
            'tags.*' => 'string',
            'page' => 'integer|min:1',
            'per_page' => 'integer|min:1|max:100'
        ];
    }
}
