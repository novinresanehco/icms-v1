<?php

namespace App\Core\CMS;

use App\Core\Security\SecurityManager;
use App\Core\Protection\SystemProtection;
use App\Core\Data\TransactionManager;

class ContentManager
{
    private SecurityManager $security;
    private SystemProtection $protection;
    private TransactionManager $transaction;
    private ValidationService $validator;
    private CacheManager $cache;

    public function __construct(
        SecurityManager $security,
        SystemProtection $protection,
        TransactionManager $transaction,
        ValidationService $validator,
        CacheManager $cache
    ) {
        $this->security = $security;
        $this->protection = $protection;
        $this->transaction = $transaction;
        $this->validator = $validator;
        $this->cache = $cache;
    }

    public function createContent(array $data, array $context): Content
    {
        return $this->security->executeCriticalOperation(function() use ($data, $context) {
            return $this->protection->executeProtectedOperation(function() use ($data, $context) {
                return $this->transaction->executeTransaction(function() use ($data) {
                    // Validate content data
                    $validated = $this->validator->validateContent($data);
                    
                    // Create content with protection
                    $content = Content::create($validated);
                    
                    // Process media if any
                    if (!empty($validated['media'])) {
                        $this->processMedia($content, $validated['media']);
                    }
                    
                    // Set permissions
                    $this->setPermissions($content, $validated['permissions'] ?? []);
                    
                    // Clear relevant cache
                    $this->cache->invalidateGroup('content');
                    
                    return $content;
                }, $context);
            }, $context);
        }, $context);
    }

    public function updateContent(int $id, array $data, array $context): Content
    {
        return $this->security->executeCriticalOperation(function() use ($id, $data, $context) {
            return $this->protection->executeProtectedOperation(function() use ($id, $data, $context) {
                return $this->transaction->executeTransaction(function() use ($id, $data) {
                    $content = $this->findOrFail($id);
                    
                    // Validate update data
                    $validated = $this->validator->validateContentUpdate($data);
                    
                    // Update with protection
                    $content->update($validated);
                    
                    // Update media if changed
                    if (isset($validated['media'])) {
                        $this->updateMedia($content, $validated['media']);
                    }
                    
                    // Update permissions if changed
                    if (isset($validated['permissions'])) {
                        $this->updatePermissions($content, $validated['permissions']);
                    }
                    
                    // Clear cache
                    $this->cache->invalidateGroup('content:' . $id);
                    
                    return $content->fresh();
                }, $context);
            }, $context);
        }, $context);
    }

    public function deleteContent(int $id, array $context): bool
    {
        return $this->security->executeCriticalOperation(function() use ($id, $context) {
            return $this->protection->executeProtectedOperation(function() use ($id, $context) {
                return $this->transaction->executeTransaction(function() use ($id) {
                    $content = $this->findOrFail($id);
                    
                    // Remove associated media
                    $this->removeMedia($content);
                    
                    // Remove permissions
                    $this->removePermissions($content);
                    
                    // Delete content
                    $deleted = $content->delete();
                    
                    // Clear cache
                    $this->cache->invalidateGroup('content:' . $id);
                    $this->cache->invalidateGroup('content');
                    
                    return $deleted;
                }, $context);
            }, $context);
        }, $context);
    }

    protected function findOrFail(int $id): Content
    {
        if (!$content = Content::find($id)) {
            throw new ContentNotFoundException("Content not found: {$id}");
        }
        return $content;
    }

    protected function processMedia(Content $content, array $media): void
    {
        foreach ($media as $item) {
            if (!$this->validator->validateMedia($item)) {
                throw new InvalidMediaException('Invalid media item');
            }
            $content->media()->create($item);
        }
    }

    protected function setPermissions(Content $content, array $permissions): void
    {
        if (!$this->validator->validatePermissions($permissions)) {
            throw new InvalidPermissionsException('Invalid permissions');
        }
        $content->permissions()->createMany($permissions);
    }
}
