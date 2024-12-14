<?php

namespace App\Core\Services;

use Illuminate\Support\Facades\{DB, Cache, Log};
use App\Core\Models\Content;
use App\Core\Repositories\ContentRepository;
use App\Core\Services\{SecurityService, ValidationService, MediaService};
use App\Core\Events\{ContentCreated, ContentUpdated, ContentDeleted};
use App\Core\Exceptions\{ContentException, ValidationException, SecurityException};

class ContentManager
{
    private ContentRepository $repository;
    private SecurityService $security;
    private ValidationService $validator;
    private MediaService $media;

    public function __construct(
        ContentRepository $repository,
        SecurityService $security,
        ValidationService $validator,
        MediaService $media
    ) {
        $this->repository = $repository;
        $this->security = $security;
        $this->validator = $validator;
        $this->media = $media;
    }

    public function create(array $data): Content
    {
        return $this->security->executeSecure(function() use ($data) {
            DB::beginTransaction();
            try {
                // Validate content data
                $validated = $this->validator->validate($data, $this->getValidationRules());
                
                // Process any media attachments
                if (isset($validated['media'])) {
                    $validated['media'] = $this->media->processUploads($validated['media']);
                }

                // Create content
                $content = $this->repository->create($validated);

                // Process tags
                if (isset($validated['tags'])) {
                    $content->syncTags($validated['tags']);
                }

                DB::commit();
                
                // Clear relevant caches
                $this->clearContentCaches();
                
                // Dispatch event
                event(new ContentCreated($content));
                
                return $content;

            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('Content creation failed', [
                    'data' => $data,
                    'error' => $e->getMessage()
                ]);
                throw new ContentException('Failed to create content: ' . $e->getMessage());
            }
        }, 'content.create');
    }

    public function update(int $id, array $data): Content
    {
        return $this->security->executeSecure(function() use ($id, $data) {
            DB::beginTransaction();
            try {
                // Get existing content
                $content = $this->repository->find($id);
                if (!$content) {
                    throw new ContentException('Content not found');
                }

                // Validate update data
                $validated = $this->validator->validate($data, $this->getValidationRules());

                // Process media updates
                if (isset($validated['media'])) {
                    $validated['media'] = $this->media->processUploads($validated['media']);
                    $this->media->cleanupUnused($content, $validated['media']);
                }

                // Update content
                $content = $this->repository->update($id, $validated);

                // Update tags
                if (isset($validated['tags'])) {
                    $content->syncTags($validated['tags']);
                }

                DB::commit();

                // Clear caches
                $this->clearContentCaches($id);

                // Dispatch event
                event(new ContentUpdated($content));

                return $content;

            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('Content update failed', [
                    'id' => $id,
                    'data' => $data,
                    'error' => $e->getMessage()
                ]);
                throw new ContentException('Failed to update content: ' . $e->getMessage());
            }
        }, 'content.update');
    }

    public function delete(int $id): bool
    {
        return $this->security->executeSecure(function() use ($id) {
            DB::beginTransaction();
            try {
                // Get content
                $content = $this->repository->find($id);
                if (!$content) {
                    throw new ContentException('Content not found');
                }

                // Delete associated media
                $this->media->deleteForContent($content);

                // Delete content
                $result = $this->repository->delete($id);

                DB::commit();

                // Clear caches
                $this->clearContentCaches($id);

                // Dispatch event
                event(new ContentDeleted($content));

                return $result;

            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('Content deletion failed', [
                    'id' => $id,
                    'error' => $e->getMessage()
                ]);
                throw new ContentException('Failed to delete content: ' . $e->getMessage());
            }
        }, 'content.delete');
    }

    public function publish(int $id): Content
    {
        return $this->security->executeSecure(function() use ($id) {
            // Get content
            $content = $this->repository->find($id);
            if (!$content) {
                throw new ContentException('Content not found');
            }

            // Validate content is ready for publishing
            $this->validator->validatePublishState($content);

            // Update publish status
            $content->published_at = now();
            $content->save();

            // Clear caches
            $this->clearContentCaches($id);

            return $content;
        }, 'content.publish');
    }

    public function find(int $id): ?Content
    {
        return Cache::remember("content.{$id}", 3600, function() use ($id) {
            return $this->repository->find($id);
        });
    }

    protected function getValidationRules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'status' => 'required|in:draft,published,archived',
            'media.*' => 'sometimes|required|file|mimes:jpeg,png,pdf|max:10240',
            'tags' => 'sometimes|array',
            'tags.*' => 'required|string|max:50'
        ];
    }

    protected function clearContentCaches(int $id = null): void
    {
        if ($id) {
            Cache::forget("content.{$id}");
        }
        Cache::tags(['content'])->flush();
    }
}
