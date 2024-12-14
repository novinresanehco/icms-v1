<?php

namespace App\Core\Services;

use App\Core\Repository\ContentRepository;
use App\Core\Repository\TagRepository;
use App\Core\Repository\MediaRepository;
use App\Core\Cache\CacheManager;
use App\Core\Events\ContentEvents;
use App\Core\Validators\ContentValidator;
use App\Core\Exceptions\ValidationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

abstract class BaseService
{
    protected CacheManager $cache;

    public function __construct(CacheManager $cache)
    {
        $this->cache = $cache;
    }

    protected function beginTransaction(): void
    {
        DB::beginTransaction();
    }

    protected function commit(): void
    {
        DB::commit();
    }

    protected function rollback(): void
    {
        DB::rollBack();
    }

    protected function clearCache(string $tag): void
    {
        $this->cache->tags([$tag])->flush();
    }
}

class ContentService extends BaseService
{
    private ContentRepository $repository;
    private TagRepository $tagRepository;
    private MediaRepository $mediaRepository;
    private ContentValidator $validator;

    public function __construct(
        ContentRepository $repository,
        TagRepository $tagRepository,
        MediaRepository $mediaRepository,
        ContentValidator $validator,
        CacheManager $cache
    ) {
        parent::__construct($cache);
        $this->repository = $repository;
        $this->tagRepository = $tagRepository;
        $this->mediaRepository = $mediaRepository;
        $this->validator = $validator;
    }

    public function createContent(array $data): Content
    {
        $this->validator->validate($data);

        $this->beginTransaction();

        try {
            // Create content
            $content = $this->repository->create($data);

            // Handle tags if present
            if (!empty($data['tags'])) {
                $this->tagRepository->attachToContent($content->id, $data['tags']);
            }

            // Handle media if present
            if (!empty($data['media'])) {
                $this->mediaRepository->attachToContent($content->id, $data['media']);
            }

            Event::dispatch(ContentEvents::CREATED, $content);

            $this->commit();
            $this->clearCache('content');

            return $content;
        } catch (\Exception $e) {
            $this->rollback();
            throw new ServiceException("Failed to create content: {$e->getMessage()}");
        }
    }

    public function updateContent(int $id, array $data): Content
    {
        $this->validator->validate($data, $id);

        $this->beginTransaction();

        try {
            $content = $this->repository->update($id, $data);

            // Update tags if present
            if (isset($data['tags'])) {
                $this->tagRepository->attachToContent($content->id, $data['tags']);
            }

            // Update media if present
            if (isset($data['media'])) {
                $this->mediaRepository->attachToContent($content->id, $data['media']);
            }

            Event::dispatch(ContentEvents::UPDATED, $content);

            $this->commit();
            $this->clearCache('content');

            return $content;
        } catch (\Exception $e) {
            $this->rollback();
            throw new ServiceException("Failed to update content: {$e->getMessage()}");
        }
    }

    public function publishContent(int $id): Content
    {
        $this->beginTransaction();

        try {
            $content = $this->repository->updateStatus($id, 'published');
            
            Event::dispatch(ContentEvents::PUBLISHED, $content);

            $this->commit();
            $this->clearCache('content');

            return $content;
        } catch (\Exception $e) {
            $this->rollback();
            throw new ServiceException("Failed to publish content: {$e->getMessage()}");
        }
    }

    public function deleteContent(int $id): bool
    {
        $this->beginTransaction();

        try {
            $content = $this->repository->findOrFail($id);
            
            // Remove all relationships
            $content->tags()->detach();
            $content->media()->detach();
            
            $deleted = $this->repository->delete($id);

            Event::dispatch(ContentEvents::DELETED, $content);

            $this->commit();
            $this->clearCache('content');

            return $deleted;
        } catch (\Exception $e) {
            $this->rollback();
            throw new ServiceException("Failed to delete content: {$e->getMessage()}");
        }
    }

    public function getPublishedContent(array $criteria = []): Collection
    {
        return $this->repository->findPublished();
    }
}

class TagService extends BaseService
{
    private TagRepository $repository;
    private ContentRepository $contentRepository;

    public function __construct(
        TagRepository $repository,
        ContentRepository $contentRepository,
        CacheManager $cache
    ) {
        parent::__construct($cache);
        $this->repository = $repository;
        $this->contentRepository = $contentRepository;
    }

    public function createTag(array $data): Tag
    {
        $this->beginTransaction();

        try {
            $tag = $this->repository->create($data);

            $this->commit();
            $this->clearCache('tags');

            return $tag;
        } catch (\Exception $e) {
            $this->rollback();
            throw new ServiceException("Failed to create tag: {$e->getMessage()}");
        }
    }

    public function getPopularTags(int $limit = 10): Collection
    {
        return $this->repository->getPopularTags($limit);
    }

    public function attachToContent(int $contentId, array $tagIds): void
    {
        $this->beginTransaction();

        try {
            $this->repository->attachToContent($contentId, $tagIds);

            $this->commit();
            $this->clearCache('tags');
            $this->clearCache('content');
        } catch (\Exception $e) {
            $this->rollback();
            throw new ServiceException("Failed to attach tags to content: {$e->getMessage()}");
        }
    }
}

class MediaService extends BaseService
{
    private MediaRepository $repository;
    private ContentRepository $contentRepository;

    public function __construct(
        MediaRepository $repository,
        ContentRepository $contentRepository,
        CacheManager $cache
    ) {
        parent::__construct($cache);
        $this->repository = $repository;
        $this->contentRepository = $contentRepository;
    }

    public function uploadMedia(UploadedFile $file, array $metadata = []): Media
    {
        $this->beginTransaction();

        try {
            // Process and store the file
            $path = $file->store('media', 'public');

            // Create media record
            $media = $this->repository->create([
                'path' => $path,
                'type' => $file->getClientMimeType(),
                'size' => $file->getSize(),
                'metadata' => $metadata,
            ]);

            $this->commit();
            $this->clearCache('media');

            return $media;
        } catch (\Exception $e) {
            $this->rollback();
            throw new ServiceException("Failed to upload media: {$e->getMessage()}");
        }
    }

    public function attachToContent(int $contentId, array $mediaIds): void
    {
        $this->beginTransaction();

        try {
            $this->repository->attachToContent($contentId, $mediaIds);

            $this->commit();
            $this->clearCache('media');
            $this->clearCache('content');
        } catch (\Exception $e) {
            $this->rollback();
            throw new ServiceException("Failed to attach media to content: {$e->getMessage()}");
        }
    }

    public function updateMetadata(int $id, array $metadata): Media
    {
        $this->beginTransaction();

        try {
            $media = $this->repository->updateMetadata($id, $metadata);

            $this->commit();
            $this->clearCache('media');

            return $media;
        } catch (\Exception $e) {
            $this->rollback();
            throw new ServiceException("Failed to update media metadata: {$e->getMessage()}");
        }
    }
}
