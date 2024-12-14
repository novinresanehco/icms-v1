<?php

namespace App\Core\Tag\Services;

use App\Core\Tag\Models\Tag;
use App\Core\Tag\Contracts\{
    TagServiceInterface,
    TagRepositoryInterface,
    TagValidatorInterface,
    TagCacheInterface
};
use App\Core\Tag\Exceptions\{
    TagNotFoundException,
    TagValidationException
};
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TagService implements TagServiceInterface
{
    protected TagRepositoryInterface $repository;
    protected TagValidatorInterface $validator;
    protected TagCacheInterface $cache;

    public function __construct(
        TagRepositoryInterface $repository,
        TagValidatorInterface $validator,
        TagCacheInterface $cache
    ) {
        $this->repository = $repository;
        $this->validator = $validator;
        $this->cache = $cache;
    }

    /**
     * Create a new tag.
     */
    public function createTag(array $data): Tag
    {
        // Validate data
        if (!$this->validator->validate($data)) {
            throw new TagValidationException(
                $this->validator->getErrors()
            );
        }

        DB::beginTransaction();
        try {
            // Create tag
            $tag = $this->repository->create($data);

            // Handle relationships if any
            if (isset($data['relationships'])) {
                $this->handleRelationships($tag, $data['relationships']);
            }

            DB::commit();

            // Clear cache
            $this->cache->flush();

            return $tag;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Update an existing tag.
     */
    public function updateTag(int $id, array $data): Tag
    {
        // Validate data
        if (!$this->validator->validate($data)) {
            throw new TagValidationException(
                $this->validator->getErrors()
            );
        }

        DB::beginTransaction();
        try {
            // Update tag
            $tag = $this->repository->update($id, $data);

            // Handle relationships if any
            if (isset($data['relationships'])) {
                $this->handleRelationships($tag, $data['relationships']);
            }

            DB::commit();

            // Clear cache
            $this->cache->forget($id);

            return $tag;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Delete a tag.
     */
    public function deleteTag(int $id): bool
    {
        DB::beginTransaction();
        try {
            $result = $this->repository->delete($id);
            DB::commit();

            // Clear cache
            $this->cache->forget($id);

            return $result;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Get tag with relationships.
     */
    public function getTagWithRelations(int $id, array $relations = []): ?Tag
    {
        // Try to get from cache first
        $tag = $this->cache->get($id);

        if (!$tag) {
            $tag = $this->repository->find($id);

            if ($tag) {
                $tag->load($relations);
                $this->cache->put($tag);
            }
        }

        if (!$tag) {
            throw new TagNotFoundException("Tag not found: {$id}");
        }

        return $tag;
    }

    /**
     * Search tags based on criteria.
     */
    public function searchTags(array $criteria): Collection
    {
        return $this->repository->search($criteria);
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
