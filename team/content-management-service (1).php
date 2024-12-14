<?php

namespace App\Core\Content\Services;

use App\Core\Content\Contracts\ContentRepositoryInterface;
use App\Core\Content\Events\ContentCreated;
use App\Core\Content\Events\ContentUpdated;
use App\Core\Content\Events\ContentDeleted;
use App\Core\Content\Validators\ContentValidator;
use App\Core\Content\DTOs\ContentDTO;
use App\Core\Cache\CacheManager;
use App\Core\Content\Models\Content;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

class ContentService
{
    private ContentRepositoryInterface $repository;
    private ContentValidator $validator;
    private CacheManager $cache;

    public function __construct(
        ContentRepositoryInterface $repository,
        ContentValidator $validator,
        CacheManager $cache
    ) {
        $this->repository = $repository;
        $this->validator = $validator;
        $this->cache = $cache;
    }

    /**
     * Create new content
     *
     * @param array $data Content data
     * @throws \App\Core\Content\Exceptions\ContentValidationException
     * @throws \App\Core\Content\Exceptions\ContentCreationException
     * @return ContentDTO
     */
    public function create(array $data): ContentDTO
    {
        // Validate input data
        $this->validator->validateForCreation($data);

        try {
            // Create content
            $content = $this->repository->create($data);

            // Broadcast event
            Event::dispatch(new ContentCreated($content));

            // Clear relevant caches
            $this->cache->tags(['content', 'content-list'])->flush();

            // Log creation
            Log::info('Content created', ['id' => $content->id, 'type' => $content->type]);

            return new ContentDTO($content);
        } catch (\Exception $e) {
            Log::error('Content creation failed', [
                'error' => $e->getMessage(),
                'data' => $data
            ]);
            throw $e;
        }
    }

    /**
     * Update existing content
     *
     * @param int $id Content identifier
     * @param array $data Updated content data
     * @throws \App\Core\Content\Exceptions\ContentNotFoundException
     * @throws \App\Core\Content\Exceptions\ContentValidationException
     * @throws \App\Core\Content\Exceptions\ContentUpdateException
     * @return ContentDTO
     */
    public function update(int $id, array $data): ContentDTO
    {
        // Validate input data
        $this->validator->validateForUpdate($id, $data);

        try {
            // Update content
            $content = $this->repository->update($id, $data);

            // Broadcast event
            Event::dispatch(new ContentUpdated($content));

            // Clear specific content cache
            $this->cache->tags(['content'])->forget("content.{$id}");

            // Log update
            Log::info('Content updated', ['id' => $id]);

            return new ContentDTO($content);
        } catch (\Exception $e) {
            Log::error('Content update failed', [
                'id' => $id,
                'error' => $e->getMessage(),
                'data' => $data
            ]);
            throw $e;
        }
    }

    /**
     * Find content by ID
     *
     * @param int $id Content identifier
     * @throws \App\Core\Content\Exceptions\ContentNotFoundException
     * @return ContentDTO
     */
    public function find(int $id): ContentDTO
    {
        return $this->cache->tags(['content'])->remember(
            "content.{$id}",
            3600,
            function () use ($id) {
                $content = $this->repository->find($id);
                return $content ? new ContentDTO($content) : null;
            }
        );
    }

    /**
     * Delete content by ID
     *
     * @param int $id Content identifier
     * @throws \App\Core\Content\Exceptions\ContentNotFoundException
     * @throws \App\Core\Content\Exceptions\ContentDeletionException
     * @return bool
     */
    public function delete(int $id): bool
    {
        try {
            // Delete content
            $result = $this->repository->delete($id);

            if ($result) {
                // Broadcast event
                Event::dispatch(new ContentDeleted($id));

                // Clear caches
                $this->cache->tags(['content', 'content-list'])->flush();

                // Log deletion
                Log::info('Content deleted', ['id' => $id]);
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('Content deletion failed', [
                'id' => $id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Publish content
     *
     * @param int $id Content identifier
     * @throws \App\Core\Content\Exceptions\ContentNotFoundException
     * @throws \App\Core\Content\Exceptions\ContentPublishException
     * @return ContentDTO
     */
    public function publish(int $id): ContentDTO
    {
        try {
            $content = $this->repository->find($id);

            if (!$content) {
                throw new ContentNotFoundException("Content with ID {$id} not found");
            }

            $content->published_at = now();
            $content->status = 'published';
            $content->save();

            // Clear caches
            $this->cache->tags(['content'])->forget("content.{$id}");

            // Log publication
            Log::info('Content published', ['id' => $id]);

            return new ContentDTO($content);
        } catch (\Exception $e) {
            Log::error('Content publication failed', [
                'id' => $id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
}
