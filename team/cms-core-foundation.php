<?php

namespace App\Core\CMS;

use App\Core\Security\SecurityManager;
use App\Core\Contracts\{ContentManagerInterface, CacheManagerInterface};
use App\Core\Exceptions\{ContentException, ValidationException};
use Illuminate\Support\Facades\{DB, Cache};

class ContentManager implements ContentManagerInterface
{
    private SecurityManager $security;
    private CacheManager $cache;
    private ValidationService $validator;
    private MediaHandler $media;
    
    public function __construct(
        SecurityManager $security,
        CacheManager $cache,
        ValidationService $validator,
        MediaHandler $media
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->validator = $validator;
        $this->media = $media;
    }

    public function create(array $data, SecurityContext $context): Content
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeCreate($data),
            $context
        );
    }

    private function executeCreate(array $data): Content
    {
        $validated = $this->validator->validate($data, [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'status' => 'required|in:draft,published',
            'media' => 'array'
        ]);

        DB::beginTransaction();
        try {
            $content = new Content($validated);
            $content->save();

            if (!empty($validated['media'])) {
                $this->media->attachMedia($content, $validated['media']);
            }

            $this->cache->invalidateContentCache($content->id);
            
            DB::commit();
            return $content;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new ContentException('Failed to create content: ' . $e->getMessage());
        }
    }

    public function update(int $id, array $data, SecurityContext $context): Content
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeUpdate($id, $data),
            $context
        );
    }

    private function executeUpdate(int $id, array $data): Content
    {
        $content = Content::findOrFail($id);
        
        $validated = $this->validator->validate($data, [
            'title' => 'string|max:255',
            'content' => 'string',
            'status' => 'in:draft,published',
            'media' => 'array'
        ]);

        DB::beginTransaction();
        try {
            $content->update($validated);

            if (isset($validated['media'])) {
                $this->media->syncMedia($content, $validated['media']);
            }

            $this->cache->invalidateContentCache($id);
            
            DB::commit();
            return $content;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new ContentException('Failed to update content: ' . $e->getMessage());
        }
    }

    public function publish(int $id, SecurityContext $context): Content
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executePublish($id),
            $context
        );
    }

    private function executePublish(int $id): Content
    {
        $content = Content::findOrFail($id);
        
        if ($content->status === 'published') {
            throw new ContentException('Content is already published');
        }

        DB::beginTransaction();
        try {
            $content->status = 'published';
            $content->published_at = now();
            $content->save();

            $this->cache->invalidateContentCache($id);
            
            DB::commit();
            return $content;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new ContentException('Failed to publish content: ' . $e->getMessage());
        }
    }

    public function delete(int $id, SecurityContext $context): bool
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeDelete($id),
            $context
        );
    }

    private function executeDelete(int $id): bool
    {
        $content = Content::findOrFail($id);

        DB::beginTransaction();
        try {
            $this->media->detachAllMedia($content);
            $content->delete();

            $this->cache->invalidateContentCache($id);
            
            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new ContentException('Failed to delete content: ' . $e->getMessage());
        }
    }

    public function get(int $id, SecurityContext $context): Content
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->cache->remember(
                "content:{$id}",
                fn() => Content::with('media')->findOrFail($id)
            ),
            $context
        );
    }
}

class MediaHandler
{
    public function attachMedia(Content $content, array $mediaIds): void
    {
        $content->media()->attach($mediaIds);
    }

    public function syncMedia(Content $content, array $mediaIds): void
    {
        $content->media()->sync($mediaIds);
    }

    public function detachAllMedia(Content $content): void
    {
        $content->media()->detach();
    }
}

class CacheManager implements CacheManagerInterface
{
    public function remember(string $key, callable $callback, int $ttl = 3600): mixed
    {
        return Cache::remember($key, $ttl, $callback);
    }

    public function invalidateContentCache(int $contentId): void
    {
        Cache::forget("content:{$contentId}");
    }
}

class ValidationService
{
    public function validate(array $data, array $rules): array
    {
        $validator = validator($data, $rules);

        if ($validator->fails()) {
            throw new ValidationException($validator->errors()->first());
        }

        return $validator->validated();
    }
}
