<?php

namespace App\Core\CMS;

use Illuminate\Support\Facades\DB;
use App\Core\Security\SecurityContext;
use App\Core\Cache\CacheManager;
use App\Core\Media\MediaService;
use App\Core\Search\SearchIndexer;
use App\Core\Events\EventDispatcher;

class ContentManagementService implements ContentManagementInterface
{
    private ContentRepository $repository;
    private CacheManager $cache;
    private MediaService $mediaService;
    private SearchIndexer $searchIndexer;
    private EventDispatcher $events;
    private ValidationService $validator;

    public function __construct(
        ContentRepository $repository,
        CacheManager $cache,
        MediaService $mediaService,
        SearchIndexer $searchIndexer,
        EventDispatcher $events,
        ValidationService $validator
    ) {
        $this->repository = $repository;
        $this->cache = $cache;
        $this->mediaService = $mediaService;
        $this->searchIndexer = $searchIndexer;
        $this->events = $events;
        $this->validator = $validator;
    }

    public function createContent(array $data, SecurityContext $context): ContentResult
    {
        DB::beginTransaction();
        try {
            // Validate content data
            $validatedData = $this->validator->validateContent($data);
            
            // Process any media attachments
            if (isset($validatedData['media'])) {
                $validatedData['media'] = $this->processMediaAttachments($validatedData['media']);
            }

            // Create the content
            $content = $this->repository->create([
                ...$validatedData,
                'author_id' => $context->getUserId(),
                'status' => ContentStatus::DRAFT,
                'created_at' => now(),
                'updated_at' => now()
            ]);

            // Process categories and tags
            if (isset($validatedData['categories'])) {
                $this->repository->syncCategories($content->id, $validatedData['categories']);
            }
            if (isset($validatedData['tags'])) {
                $this->repository->syncTags($content->id, $validatedData['tags']);
            }

            // Index for search
            $this->searchIndexer->indexContent($content);

            // Clear relevant caches
            $this->clearRelatedCaches($content);

            // Dispatch creation event
            $this->events->dispatch(new ContentCreated($content, $context));

            DB::commit();

            return new ContentResult($content, true);

        } catch (\Exception $e) {
            DB::rollBack();
            throw new ContentManagementException('Failed to create content: ' . $e->getMessage(), 0, $e);
        }
    }

    public function updateContent(int $id, array $data, SecurityContext $context): ContentResult
    {
        DB::beginTransaction();
        try {
            // Verify content exists and user has permission
            $content = $this->repository->findOrFail($id);
            $this->validateUserCanEdit($content, $context);

            // Validate update data
            $validatedData = $this->validator->validateContent($data, $content);

            // Process media changes
            if (isset($validatedData['media'])) {
                $validatedData['media'] = $this->processMediaUpdates($content, $validatedData['media']);
            }

            // Update the content
            $updatedContent = $this->repository->update($id, [
                ...$validatedData,
                'editor_id' => $context->getUserId(),
                'updated_at' => now()
            ]);

            // Update relationships if needed
            if (isset($validatedData['categories'])) {
                $this->repository->syncCategories($id, $validatedData['categories']);
            }
            if (isset($validatedData['tags'])) {
                $this->repository->syncTags($id, $validatedData['tags']);
            }

            // Update search index
            $this->searchIndexer->updateIndex($updatedContent);

            // Clear caches
            $this->clearRelatedCaches($updatedContent);

            // Dispatch update event
            $this->events->dispatch(new ContentUpdated($updatedContent, $content, $context));

            DB::commit();

            return new ContentResult($updatedContent, true);

        } catch (\Exception $e) {
            DB::rollBack();
            throw new ContentManagementException('Failed to update content: ' . $e->getMessage(), 0, $e);
        }
    }

    public function publishContent(int $id, SecurityContext $context): ContentResult
    {
        DB::beginTransaction();
        try {
            // Verify content and permissions
            $content = $this->repository->findOrFail($id);
            $this->validateUserCanPublish($content, $context);

            // Validate content is ready for publishing
            $this->validator->validatePublishableState($content);

            // Update status and publish timestamp
            $publishedContent = $this->repository->update($id, [
                'status' => ContentStatus::PUBLISHED,
                'published_at' => now(),
                'publisher_id' => $context->getUserId()
            ]);

            // Clear caches and update search
            $this->clearRelatedCaches($publishedContent);
            $this->searchIndexer->updateIndex($publishedContent);

            // Dispatch publish event
            $this->events->dispatch(new ContentPublished($publishedContent, $context));

            DB::commit();

            return new ContentResult($publishedContent, true);

        } catch (\Exception $e) {
            DB::rollBack();
            throw new ContentManagementException('Failed to publish content: ' . $e->getMessage(), 0, $e);
        }
    }

    public function deleteContent(int $id, SecurityContext $context): bool
    {
        DB::beginTransaction();
        try {
            // Verify content and permissions
            $content = $this->repository->findOrFail($id);
            $this->validateUserCanDelete($content, $context);

            // Remove from search index
            $this->searchIndexer->removeFromIndex($id);

            // Delete associated media
            $this->mediaService->deleteContentMedia($id);

            // Delete the content
            $this->repository->delete($id);

            // Clear caches
            $this->clearRelatedCaches($content);

            // Dispatch deletion event
            $this->events->dispatch(new ContentDeleted($content, $context));

            DB::commit();
            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new ContentManagementException('Failed to delete content: ' . $e->getMessage(), 0, $e);
        }
    }

    private function validateUserCanEdit(Content $content, SecurityContext $context): void
    {
        if (!$context->hasPermission('content.edit') && 
            $content->author_id !== $context->getUserId()) {
            throw new UnauthorizedException('User cannot edit this content');
        }
    }

    private function validateUserCanPublish(Content $content, SecurityContext $context): void
    {
        if (!$context->hasPermission('content.publish')) {
            throw new UnauthorizedException('User cannot publish content');
        }
    }

    private function validateUserCanDelete(Content $content, SecurityContext $context): void
    {
        if (!$context->hasPermission('content.delete') && 
            $content->author_id !== $context->getUserId()) {
            throw new UnauthorizedException('User cannot delete this content');
        }
    }

    private function processMediaAttachments(array $media): array
    {
        return array_map(function($item) {
            return $this->mediaService->processAndStore($item);
        }, $media);
    }

    private function processMediaUpdates(Content $content, array $newMedia): array
    {
        // Remove old media not in new set
        $oldMedia = $content->media->pluck('id')->toArray();
        $mediaToDelete = array_diff($oldMedia, array_column($newMedia, 'id'));
        
        foreach ($mediaToDelete as $mediaId) {
            $this->mediaService->deleteMedia($mediaId);
        }

        // Process new media
        return $this->processMediaAttachments($newMedia);
    }

    private function clearRelatedCaches(Content $content): void
    {
        $this->cache->tags(['content'])->flush();
        $this->cache->tags(['content.' . $content->id])->flush();
        if ($content->categories) {
            foreach ($content->categories as $category) {
                $this->cache->tags(['category.' . $category->id])->flush();
            }
        }
    }
}
