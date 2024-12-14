<?php

namespace App\Core\Services;

use App\Core\Repository\TagRepository;
use App\Core\Repository\ContentRepository;
use App\Core\Validation\TagValidator;
use App\Core\Events\TagEvents;
use App\Core\Exceptions\TagServiceException;
use App\Models\Tag;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class TagService
{
    public function __construct(
        protected TagRepository $repository,
        protected ContentRepository $contentRepository,
        protected TagValidator $validator
    ) {}

    /**
     * Create new tag
     */
    public function createTag(array $data): Tag
    {
        // Validate input data
        $this->validator->validateCreation($data);

        try {
            // Check for duplicate tag name
            if ($this->tagNameExists($data['name'])) {
                throw new TagServiceException("Tag with name '{$data['name']}' already exists");
            }

            return $this->repository->createTag($data);

        } catch (\Exception $e) {
            throw new TagServiceException(
                "Failed to create tag: {$e->getMessage()}", 
                0, 
                $e
            );
        }
    }

    /**
     * Update existing tag
     */
    public function updateTag(int $tagId, array $data): Tag
    {
        $this->validator->validateUpdate($data);

        try {
            DB::beginTransaction();

            $tag = $this->repository->find($tagId);
            if (!$tag) {
                throw new TagServiceException("Tag not found with ID: {$tagId}");
            }

            // Check for name uniqueness if name is being changed
            if (
                isset($data['name']) && 
                $data['name'] !== $tag->name && 
                $this->tagNameExists($data['name'])
            ) {
                throw new TagServiceException("Tag with name '{$data['name']}' already exists");
            }

            $tag = $this->repository->update($tagId, $data);
            
            DB::commit();
            
            event(new TagEvents\TagUpdated($tag));
            
            return $tag;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new TagServiceException(
                "Failed to update tag: {$e->getMessage()}", 
                0, 
                $e
            );
        }
    }

    /**
     * Delete tag
     */
    public function deleteTag(int $tagId): bool
    {
        try {
            DB::beginTransaction();

            $tag = $this->repository->find($tagId);
            if (!$tag) {
                throw new TagServiceException("Tag not found with ID: {$tagId}");
            }

            // Check if tag is in use
            if ($tag->contents()->count() > 0) {
                throw new TagServiceException("Cannot delete tag that is still in use");
            }

            $result = $this->repository->delete($tagId);
            
            DB::commit();
            
            event(new TagEvents\TagDeleted($tag));
            
            return $result;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new TagServiceException(
                "Failed to delete tag: {$e->getMessage()}", 
                0, 
                $e
            );
        }
    }

    /**
     * Get tags for content
     */
    public function getContentTags(int $contentId): Collection
    {
        try {
            // Verify content exists
            $content = $this->contentRepository->find($contentId);
            if (!$content) {
                throw new TagServiceException("Content not found with ID: {$contentId}");
            }

            return $this->repository->getContentTags($contentId);

        } catch (\Exception $e) {
            throw new TagServiceException(
                "Failed to get content tags: {$e->getMessage()}", 
                0, 
                $e
            );
        }
    }

    /**
     * Sync content tags
     */
    public function syncContentTags(int $contentId, array $tagIds): void
    {
        try {
            // Verify content exists
            $content = $this->contentRepository->find($contentId);
            if (!$content) {
                throw new TagServiceException("Content not found with ID: {$contentId}");
            }

            // Verify all tags exist
            $existingTagCount = $this->repository->model
                ->whereIn('id', $tagIds)
                ->count();
            
            if ($existingTagCount !== count($tagIds)) {
                throw new TagServiceException("One or more invalid tag IDs provided");
            }

            $this->repository->syncContentTags($contentId, $tagIds);

        } catch (\Exception $e) {
            throw new TagServiceException(
                "Failed to sync content tags: {$e->getMessage()}", 
                0, 
                $e
            );
        }
    }

    /**
     * Get tag suggestions
     */
    public function getTagSuggestions(string $query, int $limit = 5): Collection
    {
        try {
            return Cache::remember(
                "tag.suggestions.{$query}.{$limit}",
                300, // 5 minutes
                fn() => $this->repository->searchTags($query)
                    ->take($limit)
            );

        } catch (\Exception $e) {
            throw new TagServiceException(
                "Failed to get tag suggestions: {$e->getMessage()}", 
                0, 
                $e
            );
        }
    }

    /**
     * Get popular tags
     */
    public function getPopularTags(int $limit = 10): Collection
    {
        try {
            return $this->repository->getPopularTags($limit);
            
        } catch (\Exception $e) {
            throw new TagServiceException(
                "Failed to get popular tags: {$e->getMessage()}", 
                0, 
                $e
            );
        }
    }

    /**
     * Check if tag name exists
     */
    protected function tagNameExists(string $name): bool
    {
        return $this->repository->model
            ->where('name', $name)
            ->exists();
    }
}
