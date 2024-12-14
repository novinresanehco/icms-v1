<?php

namespace App\Core\Tag\Repository;

use App\Core\Tag\Models\Tag;
use App\Core\Tag\Events\{TagCreated, TagUpdated, TagDeleted};
use App\Core\Tag\Exceptions\TagWriteException;
use App\Core\Tag\Contracts\TagWriteInterface;
use Illuminate\Support\Facades\DB;

class TagWriteRepository implements TagWriteInterface
{
    /**
     * @var Tag
     */
    protected Tag $model;

    /**
     * @var TagCacheRepository
     */
    protected TagCacheRepository $cacheRepository;

    public function __construct(Tag $model, TagCacheRepository $cacheRepository)
    {
        $this->model = $model;
        $this->cacheRepository = $cacheRepository;
    }

    /**
     * Create a new tag.
     */
    public function create(array $data): Tag
    {
        try {
            DB::beginTransaction();

            $tag = $this->model->create($data);

            if (isset($data['metadata'])) {
                $tag->metadata()->create($data['metadata']);
            }

            if (isset($data['relationships'])) {
                $this->handleRelationships($tag, $data['relationships']);
            }

            DB::commit();

            // Clear relevant caches
            $this->cacheRepository->clearTagCache();
            
            // Dispatch event
            event(new TagCreated($tag));

            return $tag;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new TagWriteException("Failed to create tag: {$e->getMessage()}");
        }
    }

    /**
     * Update an existing tag.
     */
    public function update(int $id, array $data): Tag
    {
        try {
            DB::beginTransaction();

            $tag = $this->model->findOrFail($id);
            $tag->update($data);

            if (isset($data['metadata'])) {
                $tag->metadata()->update($data['metadata']);
            }

            if (isset($data['relationships'])) {
                $this->handleRelationships($tag, $data['relationships']);
            }

            DB::commit();

            // Clear relevant caches
            $this->cacheRepository->clearTagCache($id);
            
            // Dispatch event
            event(new TagUpdated($tag));

            return $tag->fresh();
        } catch (\Exception $e) {
            DB::rollBack();
            throw new TagWriteException("Failed to update tag: {$e->getMessage()}");
        }
    }

    /**
     * Delete a tag.
     */
    public function delete(int $id): bool
    {
        try {
            DB::beginTransaction();

            $tag = $this->model->findOrFail($id);

            // Handle relationships before deletion
            $tag->contents()->detach();
            $tag->metadata()->delete();

            $result = $tag->delete();

            DB::commit();

            // Clear relevant caches
            $this->cacheRepository->clearTagCache($id);
            
            // Dispatch event
            event(new TagDeleted($id));

            return $result;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new TagWriteException("Failed to delete tag: {$e->getMessage()}");
        }
    }

    /**
     * Bulk update tags.
     */
    public function bulkUpdate(array $updates): int
    {
        try {
            DB::beginTransaction();

            $updatedCount = 0;
            foreach ($updates as $id => $data) {
                $tag = $this->model->find($id);
                if ($tag) {
                    $tag->update($data);
                    $updatedCount++;
                }
            }

            DB::commit();

            // Clear all tag caches after bulk update
            $this->cacheRepository->clearAllTagCaches();

            return $updatedCount;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new TagWriteException("Failed to perform bulk update: {$e->getMessage()}");
        }
    }

    /**
     * Handle tag relationships.
     */
    protected function handleRelationships(Tag $tag, array $relationships): void
    {
        if (isset($relationships['content_ids'])) {
            $tag->contents()->sync($relationships['content_ids']);
        }

        if (isset($relationships['parent_ids'])) {
            $tag->parents()->sync($relationships['parent_ids']);
        }

        if (isset($relationships['child_ids'])) {
            $tag->children()->sync($relationships['child_ids']);
        }
    }
}
