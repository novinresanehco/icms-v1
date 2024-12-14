<?php

namespace App\Core\Content;

use Illuminate\Support\Facades\{DB, Cache, Log};
use App\Core\Security\SecurityManager;
use App\Core\Services\{ValidationService, AuditService};
use App\Core\Exceptions\{ContentException, ValidationException};

class ContentManager implements ContentManagementInterface
{
    private SecurityManager $security;
    private ValidationService $validator;
    private AuditService $audit;
    private array $config;
    
    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        AuditService $audit,
        array $config
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->audit = $audit;
        $this->config = $config;
    }

    public function create(array $data): Content
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeCreate($data),
            ['action' => 'content.create', 'data' => $data]
        );
    }

    protected function executeCreate(array $data): Content
    {
        $validated = $this->validator->validate($data, [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'status' => 'required|in:draft,published',
            'type' => 'required|string',
            'meta' => 'array'
        ]);

        DB::beginTransaction();
        try {
            // Create content version
            $version = ContentVersion::create([
                'content' => $validated['content'],
                'meta' => $validated['meta'] ?? [],
                'created_by' => auth()->id(),
            ]);

            // Create content
            $content = Content::create([
                'title' => $validated['title'],
                'type' => $validated['type'],
                'status' => $validated['status'],
                'current_version_id' => $version->id,
                'created_by' => auth()->id(),
            ]);

            // Link version to content
            $version->content_id = $content->id;
            $version->save();

            DB::commit();

            // Clear relevant caches
            $this->clearCaches($content);

            // Audit trail
            $this->audit->log('content.created', [
                'content_id' => $content->id,
                'version_id' => $version->id
            ]);

            return $content->load('currentVersion');

        } catch (\Exception $e) {
            DB::rollBack();
            throw new ContentException('Failed to create content: ' . $e->getMessage());
        }
    }

    public function update(int $id, array $data): Content
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeUpdate($id, $data),
            ['action' => 'content.update', 'id' => $id, 'data' => $data]
        );
    }

    protected function executeUpdate(int $id, array $data): Content
    {
        $content = Content::findOrFail($id);
        
        $validated = $this->validator->validate($data, [
            'title' => 'string|max:255',
            'content' => 'string',
            'status' => 'in:draft,published',
            'meta' => 'array'
        ]);

        DB::beginTransaction();
        try {
            if (isset($validated['content'])) {
                // Create new version
                $version = ContentVersion::create([
                    'content_id' => $content->id,
                    'content' => $validated['content'],
                    'meta' => $validated['meta'] ?? $content->currentVersion->meta,
                    'created_by' => auth()->id(),
                ]);

                $content->current_version_id = $version->id;
            }

            // Update content
            $content->fill($validated)->save();

            DB::commit();

            // Clear caches
            $this->clearCaches($content);

            // Audit trail
            $this->audit->log('content.updated', [
                'content_id' => $content->id,
                'version_id' => $content->current_version_id
            ]);

            return $content->load('currentVersion');

        } catch (\Exception $e) {
            DB::rollBack();
            throw new ContentException('Failed to update content: ' . $e->getMessage());
        }
    }

    public function delete(int $id): bool
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeDelete($id),
            ['action' => 'content.delete', 'id' => $id]
        );
    }

    protected function executeDelete(int $id): bool
    {
        $content = Content::findOrFail($id);

        DB::beginTransaction();
        try {
            // Soft delete all versions
            ContentVersion::where('content_id', $id)->delete();
            
            // Soft delete content
            $content->delete();

            DB::commit();

            // Clear caches
            $this->clearCaches($content);

            // Audit trail
            $this->audit->log('content.deleted', [
                'content_id' => $id
            ]);

            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new ContentException('Failed to delete content: ' . $e->getMessage());
        }
    }

    public function publish(int $id): bool
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executePublish($id),
            ['action' => 'content.publish', 'id' => $id]
        );
    }

    protected function executePublish(int $id): bool
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

            DB::commit();

            // Clear caches
            $this->clearCaches($content);

            // Audit trail
            $this->audit->log('content.published', [
                'content_id' => $id
            ]);

            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new ContentException('Failed to publish content: ' . $e->getMessage());
        }
    }

    public function revertToVersion(int $contentId, int $versionId): Content
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeRevertToVersion($contentId, $versionId),
            ['action' => 'content.revert', 'content_id' => $contentId, 'version_id' => $versionId]
        );
    }

    protected function executeRevertToVersion(int $contentId, int $versionId): Content
    {
        $content = Content::findOrFail($contentId);
        $version = ContentVersion::where('content_id', $contentId)
            ->where('id', $versionId)
            ->firstOrFail();

        DB::beginTransaction();
        try {
            $content->current_version_id = $version->id;
            $content->save();

            DB::commit();

            // Clear caches
            $this->clearCaches($content);

            // Audit trail
            $this->audit->log('content.reverted', [
                'content_id' => $contentId,
                'version_id' => $versionId
            ]);

            return $content->load('currentVersion');

        } catch (\Exception $e) {
            DB::rollBack();
            throw new ContentException('Failed to revert content version: ' . $e->getMessage());
        }
    }

    protected function clearCaches(Content $content): void
    {
        $cacheKeys = [
            "content.{$content->id}",
            "content.{$content->slug}",
            'content.list',
            "content.type.{$content->type}"
        ];

        foreach ($cacheKeys as $key) {
            Cache::forget($key);
        }
    }
}
