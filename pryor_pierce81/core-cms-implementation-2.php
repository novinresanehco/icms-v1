<?php

namespace App\Core\CMS;

class ContentManager implements ContentManagerInterface 
{
    private SecurityManager $security;
    private Repository $repository;
    private CacheManager $cache;
    private ValidationService $validator;
    private VersionControl $versionControl;
    private EventDispatcher $events;
    private MediaManager $mediaManager;

    public function store(array $data): ContentResult 
    {
        DB::beginTransaction();
        
        try {
            // Security and validation
            $this->security->validateCriticalOperation([
                'action' => 'content.store',
                'data' => $data
            ]);
            
            $validated = $this->validator->validate($data, [
                'title' => 'required|string|max:255',
                'content' => 'required|string',
                'status' => 'required|in:draft,published',
                'meta' => 'array'
            ]);

            // Create version
            $version = $this->versionControl->createVersion($validated);
            
            // Store content
            $content = $this->repository->create([
                ...$validated,
                'version_id' => $version->id,
                'checksum' => $this->generateChecksum($validated)
            ]);

            // Process media
            if (isset($data['media'])) {
                $this->mediaManager->processContentMedia($content, $data['media']);
            }

            // Cache management
            $this->cache->tags(['content'])->put(
                $this->getCacheKey($content->id),
                $content,
                config('cms.cache.ttl')
            );

            $this->events->dispatch(new ContentCreated($content));
            
            DB::commit();
            
            return new ContentResult($content);

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function update(int $id, array $data): ContentResult
    {
        DB::beginTransaction();

        try {
            $this->security->validateCriticalOperation([
                'action' => 'content.update',
                'content_id' => $id,
                'data' => $data
            ]);

            $content = $this->repository->findOrFail($id);
            
            $validated = $this->validator->validate($data, [
                'title' => 'string|max:255',
                'content' => 'string',
                'status' => 'in:draft,published',
                'meta' => 'array'
            ]);

            // Create new version
            $version = $this->versionControl->createVersion([
                ...$validated,
                'content_id' => $id,
                'previous_version' => $content->version_id
            ]);

            // Update content
            $updated = $this->repository->update($id, [
                ...$validated,
                'version_id' => $version->id,
                'checksum' => $this->generateChecksum($validated)
            ]);

            // Update media
            if (isset($data['media'])) {
                $this->mediaManager->updateContentMedia($updated, $data['media']);
            }

            // Cache management
            $this->cache->tags(['content'])->forget($this->getCacheKey($id));
            $this->cache->tags(['content'])->put(
                $this->getCacheKey($id),
                $updated,
                config('cms.cache.ttl')
            );

            $this->events->dispatch(new ContentUpdated($updated));
            
            DB::commit();
            
            return new ContentResult($updated);

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function delete(int $id): bool
    {
        DB::beginTransaction();

        try {
            $this->security->validateCriticalOperation([
                'action' => 'content.delete',
                'content_id' => $id
            ]);

            $content = $this->repository->findOrFail($id);
            
            // Archive versions
            $this->versionControl->archiveVersions($content->id);
            
            // Delete media
            $this->mediaManager->deleteContentMedia($content);
            
            // Delete content
            $this->repository->delete($id);
            
            // Clear cache
            $this->cache->tags(['content'])->forget($this->getCacheKey($id));
            
            $this->events->dispatch(new ContentDeleted($id));
            
            DB::commit();
            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function find(int $id): ?ContentResult
    {
        return $this->cache->tags(['content'])->remember(
            $this->getCacheKey($id),
            config('cms.cache.ttl'),
            function () use ($id) {
                $content = $this->repository->find($id);
                return $content ? new ContentResult($content) : null;
            }
        );
    }

    public function publish(int $id): ContentResult
    {
        DB::beginTransaction();

        try {
            $this->security->validateCriticalOperation([
                'action' => 'content.publish',
                'content_id' => $id
            ]);

            $content = $this->repository->findOrFail($id);
            
            // Validate content before publishing
            $this->validator->validateForPublishing($content);
            
            $published = $this->repository->update($id, [
                'status' => 'published',
                'published_at' => now()
            ]);

            // Clear cache
            $this->cache->tags(['content'])->forget($this->getCacheKey($id));
            
            $this->events->dispatch(new ContentPublished($published));
            
            DB::commit();
            return new ContentResult($published);

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function generateChecksum(array $data): string
    {
        return hash('sha256', json_encode($data));
    }

    private function getCacheKey(int $id): string
    {
        return "content.{$id}";
    }
}
