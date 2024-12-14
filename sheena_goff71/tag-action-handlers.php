<?php

namespace App\Core\Tag\Services\Actions;

use App\Core\Tag\Models\Tag;
use App\Core\Tag\Contracts\TagServiceInterface;
use App\Core\Tag\Services\{TagService, TagCacheService};
use App\Core\Tag\Exceptions\{TagOperationException, TagPermissionException};
use Illuminate\Support\Facades\{DB, Log};

class CreateTagAction
{
    protected TagService $tagService;
    protected TagCacheService $cacheService;

    public function __construct(TagService $tagService, TagCacheService $cacheService)
    {
        $this->tagService = $tagService;
        $this->cacheService = $cacheService;
    }

    public function execute(array $data): Tag
    {
        $this->authorize('create-tag');

        DB::beginTransaction();
        try {
            $tag = $this->tagService->createTag($data);

            if (isset($data['metadata'])) {
                $this->handleMetadata($tag, $data['metadata']);
            }

            DB::commit();
            $this->cacheService->clearTagCache();

            Log::info('Tag created successfully', ['tag_id' => $tag->id]);

            return $tag;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Tag creation failed', ['error' => $e->getMessage()]);
            throw new TagOperationException("Failed to create tag: {$e->getMessage()}");
        }
    }

    protected function authorize(string $ability): void
    {
        if (!auth()->user()->can($ability)) {
            throw new TagPermissionException("Unauthorized to create tags");
        }
    }

    protected function handleMetadata(Tag $tag, array $metadata): void
    {
        $tag->metadata()->create($metadata);
    }
}

class UpdateTagAction
{
    protected TagService $tagService;
    protected TagCacheService $cacheService;

    public function __construct(TagService $tagService, TagCacheService $cacheService)
    {
        $this->tagService = $tagService;
        $this->cacheService = $cacheService;
    }

    public function execute(int $id, array $data): Tag
    {
        $tag = $this->tagService->findOrFail($id);
        $this->authorize('update-tag', $tag);

        DB::beginTransaction();
        try {
            $tag = $this->tagService->updateTag($id, $data);
            
            if (isset($data['metadata'])) {
                $this->updateMetadata($tag, $data['metadata']);
            }

            DB::commit();
            $this->cacheService->clearTagCache($id);

            Log::info('Tag updated successfully', ['tag_id' => $id]);

            return $tag;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Tag update failed', ['tag_id' => $id, 'error' => $e->getMessage()]);
            throw new TagOperationException("Failed to update tag: {$e->getMessage()}");
        }
    }

    protected function authorize(string $ability, Tag $tag): void
    {
        if (!auth()->user()->can($ability, $tag)) {
            throw new TagPermissionException("Unauthorized to update this tag");
        }
    }

    protected function updateMetadata(Tag $tag, array $metadata): void
    {
        $tag->metadata()->update($metadata);
    }
}

class DeleteTagAction
{
    protected TagService $tagService;
    protected TagCacheService $cacheService;

    public function __construct(TagService $tagService, TagCacheService $cacheService)
    {
        $this->tagService = $tagService;
        $this->cacheService = $cacheService;
    }

    public function execute(int $id, bool $force = false): bool
    {
        $tag = $this->tagService->findOrFail($id);
        $this->authorize('delete-tag', $tag);

        DB::beginTransaction();
        try {
            if ($force) {
                $result = $this->tagService->forceDelete($id);
            } else {
                $result = $this->tagService->deleteTag($id);
            }

            DB::commit();
            $this->cacheService->clearTagCache($id);

            Log::info('Tag deleted successfully', [
                'tag_id' => $id,
                'force' => $force
            ]);

            return $result;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Tag deletion failed', [
                'tag_id' => $id,
                'error' => $e->getMessage()
            ]);
            throw new TagOperationException("Failed to delete tag: {$e->getMessage()}");
        }
    }

    protected function authorize(string $ability, Tag $tag): void
    {
        if (!auth()->user()->can($ability, $tag)) {
            throw new TagPermissionException("Unauthorized to delete this tag");
        }
    }
}

class BulkTagAction
{
    protected TagService $tagService;
    protected TagCacheService $cacheService;

    public function __construct(TagService $tagService, TagCacheService $cacheService)
    {
        $this->tagService = $tagService;
        $this->cacheService = $cacheService;
    }

    public function execute(string $action, array $tagIds, array $data = []): array
    {
        $this->authorize("bulk-{$action}-tags");

        DB::beginTransaction();
        try {
            $results = [];
            foreach ($tagIds as $tagId) {
                $results[$tagId] = match($action) {
                    'update' => $this->tagService->updateTag($tagId, $data),
                    'delete' => $this->tagService->deleteTag($tagId),
                    'restore' => $this->tagService->restoreTag($tagId),
                    default => throw new TagOperationException("Invalid bulk action: {$action}")
                };
            }

            DB::commit();
            $this->cacheService->clearTagCache();

            Log::info('Bulk tag operation completed', [
                'action' => $action,
                'tag_count' => count($tagIds)
            ]);

            return $results;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Bulk tag operation failed', [
                'action' => $action,
                'error' => $e->getMessage()
            ]);
            throw new TagOperationException("Failed to perform bulk operation: {$e->getMessage()}");
        }
    }

    protected function authorize(string $ability): void
    {
        if (!auth()->user()->can($ability)) {
            throw new TagPermissionException("Unauthorized to perform bulk tag operations");
        }
    }
}
