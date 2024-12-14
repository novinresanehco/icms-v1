<?php

namespace App\Core\CMS;

use App\Core\Security\SecurityManager;
use App\Core\CMS\Models\Content;
use App\Core\CMS\Services\{MediaService, ValidationService, CacheService};
use Illuminate\Support\Facades\DB;
use App\Core\CMS\Events\ContentEvent;

class ContentManager implements ContentManagerInterface 
{
    private SecurityManager $security;
    private MediaService $media;
    private ValidationService $validator;
    private CacheService $cache;

    public function __construct(
        SecurityManager $security,
        MediaService $media,
        ValidationService $validator,
        CacheService $cache
    ) {
        $this->security = $security;
        $this->media = $media;
        $this->validator = $validator;
        $this->cache = $cache;
    }

    public function create(array $data): Content
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->handleCreate($data),
            ['operation' => 'content_create']
        );
    }

    public function update(int $id, array $data): Content 
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->handleUpdate($id, $data),
            ['operation' => 'content_update']
        );
    }

    public function publish(int $id): bool
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->handlePublish($id),
            ['operation' => 'content_publish']
        );
    }

    public function delete(int $id): bool
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->handleDelete($id),
            ['operation' => 'content_delete']
        );
    }

    private function handleCreate(array $data): Content
    {
        $validated = $this->validator->validateContent($data);
        
        DB::beginTransaction();
        try {
            $content = Content::create($validated);

            if (isset($data['media'])) {
                $this->media->attachMedia($content, $data['media']);
            }

            event(new ContentEvent('created', $content));
            
            $this->cache->invalidateContentCache();
            
            DB::commit();
            return $content;

        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function handleUpdate(int $id, array $data): Content
    {
        $validated = $this->validator->validateContent($data);
        
        DB::beginTransaction();
        try {
            $content = Content::findOrFail($id);
            $content->update($validated);

            if (isset($data['media'])) {
                $this->media->syncMedia($content, $data['media']);
            }

            event(new ContentEvent('updated', $content));
            
            $this->cache->invalidateContentCache();
            
            DB::commit();
            return $content;

        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function handlePublish(int $id): bool
    {
        DB::beginTransaction();
        try {
            $content = Content::findOrFail($id);
            
            if (!$this->validator->canPublish($content)) {
                throw new \Exception('Content cannot be published');
            }

            $content->publish();
            event(new ContentEvent('published', $content));
            
            $this->cache->invalidateContentCache();
            
            DB::commit();
            return true;

        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function handleDelete(int $id): bool
    {
        DB::beginTransaction();
        try {
            $content = Content::findOrFail($id);
            
            $this->media->detachAllMedia($content);
            $content->delete();

            event(new ContentEvent('deleted', $content));
            
            $this->cache->invalidateContentCache();
            
            DB::commit();
            return true;

        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
