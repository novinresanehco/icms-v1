<?php

namespace App\Core\CMS;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Auth\AuthorizationInterface;
use App\Core\Events\ContentEvents;
use Illuminate\Support\Facades\{DB, Cache, Event};
use App\Core\Exceptions\{ContentException, ValidationException};

class ContentManager implements ContentManagerInterface
{
    private SecurityManagerInterface $security;
    private AuthorizationInterface $auth;
    private ContentRepository $repository;
    private ValidationService $validator;
    private CacheManager $cache;
    private MediaManager $media;

    private const CACHE_PREFIX = 'content:';
    private const CACHE_TTL = 3600;

    public function __construct(
        SecurityManagerInterface $security,
        AuthorizationInterface $auth,
        ContentRepository $repository,
        ValidationService $validator,
        CacheManager $cache,
        MediaManager $media
    ) {
        $this->security = $security;
        $this->auth = $auth;
        $this->repository = $repository;
        $this->validator = $validator;
        $this->cache = $cache;
        $this->media = $media;
    }

    public function create(array $data, User $user): Content
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->handleCreate($data, $user),
            new SecurityContext('content-create', ['user' => $user->id])
        );
    }

    private function handleCreate(array $data, User $user): Content
    {
        // Verify creation permission
        if (!$this->auth->checkPermission($user, 'content.create')) {
            throw new ContentException('Unauthorized content creation attempt');
        }

        // Validate content data
        $validatedData = $this->validator->validate($data, $this->getCreationRules());

        // Handle media attachments if present
        if (!empty($validatedData['media'])) {
            $validatedData['media'] = $this->processMedia($validatedData['media']);
        }

        // Create content with version tracking
        DB::beginTransaction();
        try {
            $content = $this->repository->create([
                ...$validatedData,
                'author_id' => $user->id,
                'version' => 1,
                'status' => ContentStatus::DRAFT
            ]);

            // Create initial version record
            $this->repository->createVersion($content, $validatedData, $user);

            DB::commit();

            // Clear relevant caches
            $this->clearContentCaches($content);

            // Dispatch creation event
            Event::dispatch(new ContentEvents\ContentCreated($content, $user));

            return $content;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new ContentException('Content creation failed: ' . $e->getMessage());
        }
    }

    public function update(int $id, array $data, User $user): Content
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->handleUpdate($id, $data, $user),
            new SecurityContext('content-update', [
                'content_id' => $id,
                'user' => $user->id
            ])
        );
    }

    private function handleUpdate(int $id, array $data, User $user): Content
    {
        $content = $this->repository->findOrFail($id);

        // Verify update permission
        if (!$this->auth->checkPermission($user, 'content.update', ['content' => $content])) {
            throw new ContentException('Unauthorized content update attempt');
        }

        // Validate update data
        $validatedData = $this->validator->validate($data, $this->getUpdateRules($content));

        // Handle media updates
        if (isset($validatedData['media'])) {
            $validatedData['media'] = $this->processMedia($validatedData['media']);
        }

        DB::beginTransaction();
        try {
            // Create new version
            $version = $content->version + 1;
            $this->repository->createVersion($content, $validatedData, $user);

            // Update content
            $content = $this->repository->update($content->id, [
                ...$validatedData,
                'version' => $version,
                'updated_by' => $user->id
            ]);

            DB::commit();

            // Clear caches
            $this->clearContentCaches($content);

            // Dispatch update event
            Event::dispatch(new ContentEvents\ContentUpdated($content, $user));

            return $content;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new ContentException('Content update failed: ' . $e->getMessage());
        }
    }

    public function publish(int $id, User $user): Content
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->handlePublish($id, $user),
            new SecurityContext('content-publish', [
                'content_id' => $id,
                'user' => $user->id
            ])
        );
    }

    private function handlePublish(int $id, User $user): Content
    {
        $content = $this->repository->findOrFail($id);

        // Verify publish permission
        if (!$this->auth->checkPermission($user, 'content.publish')) {
            throw new ContentException('Unauthorized content publish attempt');
        }

        // Validate content is publishable
        if (!$this->validatePublishState($content)) {
            throw new ContentException('Content is not in a publishable state');
        }

        DB::beginTransaction();
        try {
            $content = $this->repository->update($content->id, [
                'status' => ContentStatus::PUBLISHED,
                'published_at' => now(),
                'published_by' => $user->id
            ]);

            DB::commit();

            // Clear caches
            $this->clearContentCaches($content);

            // Dispatch publish event
            Event::dispatch(new ContentEvents\ContentPublished($content, $user));

            return $content;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new ContentException('Content publication failed: ' . $e->getMessage());
        }
    }

    public function delete(int $id, User $user): void
    {
        $this->security->executeCriticalOperation(
            fn() => $this->handleDelete($id, $user),
            new SecurityContext('content-delete', [
                'content_id' => $id,
                'user' => $user->id
            ])
        );
    }

    private function handleDelete(int $id, User $user): void
    {
        $content = $this->repository->findOrFail($id);

        // Verify delete permission
        if (!$this->auth->checkPermission($user, 'content.delete', ['content' => $content])) {
            throw new ContentException('Unauthorized content deletion attempt');
        }

        DB::beginTransaction();
        try {
            // Soft delete content
            $this->repository->softDelete($content->id, $user->id);

            // Handle media cleanup if needed
            if (!empty($content->media)) {
                $this->media->handleContentDeletion($content);
            }

            DB::commit();

            // Clear caches
            $this->clearContentCaches($content);

            // Dispatch deletion event
            Event::dispatch(new ContentEvents\ContentDeleted($content, $user));

        } catch (\Exception $e) {
            DB::rollBack();
            throw new ContentException('Content deletion failed: ' . $e->getMessage());
        }
    }

    private function processMedia(array $mediaData): array
    {
        return $this->media->processContentMedia($mediaData);
    }

    private function validatePublishState(Content $content): bool
    {
        return $content->status === ContentStatus::DRAFT 
            && !empty($content->title) 
            && !empty($content->content);
    }

    private function clearContentCaches(Content $content): void
    {
        $this->cache->deletePattern(self::CACHE_PREFIX . "_{$content->id}_*");
        $this->cache->delete('content_list');
    }

    private function getCreationRules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'type' => 'required|string|in:page,post,article',
            'category_id' => 'nullable|exists:categories,id',
            'media' => 'nullable|array',
            'meta' => 'nullable|array'
        ];
    }

    private function getUpdateRules(Content $content): array
    {
        return [
            'title' => 'string|max:255',
            'content' => 'string',
            'type' => 'string|in:page,post,article',
            'category_id' => 'nullable|exists:categories,id',
            'media' => 'nullable|array',
            'meta' => 'nullable|array'
        ];
    }
}
