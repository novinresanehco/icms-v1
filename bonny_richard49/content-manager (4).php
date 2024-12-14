<?php

namespace App\Core\Content;

use App\Core\Security\CoreSecurityManager;
use App\Core\Services\ValidationService;
use App\Core\Services\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use App\Core\Exceptions\ContentException;

class ContentManager
{
    private CoreSecurityManager $security;
    private ValidationService $validator;
    private AuditService $audit;
    private array $config;

    public function __construct(
        CoreSecurityManager $security,
        ValidationService $validator,
        AuditService $audit,
        array $config
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->audit = $audit;
        $this->config = $config;
    }

    public function createContent(array $data): Content
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeCreate($data),
            ['operation' => 'content_create', 'data' => $data]
        );
    }

    public function updateContent(int $id, array $data): Content
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeUpdate($id, $data),
            ['operation' => 'content_update', 'id' => $id, 'data' => $data]
        );
    }

    public function deleteContent(int $id): bool
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeDelete($id),
            ['operation' => 'content_delete', 'id' => $id]
        );
    }

    public function publishContent(int $id): bool
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executePublish($id),
            ['operation' => 'content_publish', 'id' => $id]
        );
    }

    private function executeCreate(array $data): Content
    {
        $this->validateContentData($data);

        DB::beginTransaction();
        try {
            $content = new Content($data);
            $content->save();

            if (isset($data['meta'])) {
                $this->saveContentMeta($content->id, $data['meta']);
            }

            if (isset($data['tags'])) {
                $this->saveContentTags($content->id, $data['tags']);
            }

            DB::commit();
            $this->clearContentCache();
            
            return $content;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new ContentException('Failed to create content: ' . $e->getMessage());
        }
    }

    private function executeUpdate(int $id, array $data): Content
    {
        $this->validateContentData($data);
        
        DB::beginTransaction();
        try {
            $content = Content::findOrFail($id);
            $content->update($data);

            if (isset($data['meta'])) {
                $this->updateContentMeta($id, $data['meta']);
            }

            if (isset($data['tags'])) {
                $this->updateContentTags($id, $data['tags']);
            }

            DB::commit();
            $this->clearContentCache($id);
            
            return $content;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new ContentException('Failed to update content: ' . $e->getMessage());
        }
    }

    private function executeDelete(int $id): bool
    {
        DB::beginTransaction();
        try {
            $content = Content::findOrFail($id);
            
            // Remove related data
            $content->meta()->delete();
            $content->tags()->detach();
            $content->delete();

            DB::commit();
            $this->clearContentCache($id);
            
            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new ContentException('Failed to delete content: ' . $e->getMessage());
        }
    }

    private function executePublish(int $id): bool
    {
        DB::beginTransaction();
        try {
            $content = Content::findOrFail($id);
            
            // Validate content is ready for publishing
            $this->validatePublishState($content);
            
            $content->published_at = now();
            $content->status = 'published';
            $content->save();

            // Create content version
            $this->createContentVersion($content);

            DB::commit();
            $this->clearContentCache($id);
            
            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new ContentException('Failed to publish content: ' . $e->getMessage());
        }
    }

    private function validateContentData(array $data): void
    {
        $rules = [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'type' => 'required|string|in:' . implode(',', $this->config['allowed_types']),
            'status' => 'required|string|in:draft,published,archived',
            'meta' => 'sometimes|array',
            'tags' => 'sometimes|array'
        ];

        if (!$this->validator->validateData($data, $rules)) {
            throw new ContentException('Invalid content data');
        }
    }

    private function validatePublishState(Content $content): void
    {
        if ($content->status === 'archived') {
            throw new ContentException('Cannot publish archived content');
        }

        if (empty($content->title) || empty($content->content)) {
            throw new ContentException('Content missing required fields');
        }
    }

    private function saveContentMeta(int $contentId, array $meta): void
    {
        foreach ($meta as $key => $value) {
            ContentMeta::create([
                'content_id' => $contentId,
                'key' => $key,
                'value' => $value
            ]);
        }
    }

    private function updateContentMeta(int $contentId, array $meta): void
    {
        ContentMeta::where('content_id', $contentId)->delete();
        $this->saveContentMeta($contentId, $meta);
    }

    private function saveContentTags(int $contentId, array $tags): void
    {
        $content = Content::findOrFail($contentId);
        $content->tags()->sync($tags);
    }

    private function updateContentTags(int $contentId, array $tags): void
    {
        $this->saveContentTags($contentId, $tags);
    }

    private function createContentVersion(Content $content): void
    {
        ContentVersion::create([
            'content_id' => $content->id,
            'data' => $content->toArray(),
            'created_by' => auth()->id()
        ]);
    }

    private function clearContentCache(int $id = null): void
    {
        if ($id) {
            Cache::tags(['content'])->forget("content:{$id}");
        } else {
            Cache::tags(['content'])->flush();
        }
    }
}