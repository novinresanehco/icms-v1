<?php

namespace App\Core\Content;

use App\Core\Security\SecurityContext;
use App\Core\Validation\ValidationService;
use App\Core\Cache\CacheManager;
use Illuminate\Support\Facades\DB;

class ContentManager
{
    private Repository $repository;
    private ValidationService $validator;
    private CacheManager $cache;
    private SecurityContext $security;

    public function __construct(
        Repository $repository,
        ValidationService $validator,
        CacheManager $cache,
        SecurityContext $security
    ) {
        $this->repository = $repository;
        $this->validator = $validator;
        $this->cache = $cache;
        $this->security = $security;
    }

    public function create(array $data): Content
    {
        // Validate input data
        $validated = $this->validator->validate($data, [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'status' => 'required|in:draft,published,archived',
            'author_id' => 'required|exists:users,id'
        ]);

        // Security check
        $this->security->checkPermission('content.create');

        DB::beginTransaction();

        try {
            // Create content
            $content = $this->repository->create($validated);

            // Handle media attachments if present
            if (isset($validated['media'])) {
                $this->handleMediaAttachments($content, $validated['media']);
            }

            // Set metadata
            $content->setMetadata([
                'created_by' => $this->security->getCurrentUser()->id,
                'created_at' => now(),
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent()
            ]);

            DB::commit();

            // Clear relevant cache
            $this->cache->tags(['content'])->flush();

            return $content;

        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function update(int $id, array $data): Content
    {
        // Load existing content
        $content = $this->repository->find($id);
        if (!$content) {
            throw new ContentNotFoundException("Content not found: {$id}");
        }

        // Security check
        $this->security->checkPermission('content.update', $content);

        // Validate update data
        $validated = $this->validator->validate($data, [
            'title' => 'string|max:255',
            'content' => 'string',
            'status' => 'in:draft,published,archived'
        ]);

        DB::beginTransaction();

        try {
            // Update content
            $content = $this->repository->update($id, $validated);

            // Update media if needed
            if (isset($validated['media'])) {
                $this->handleMediaAttachments($content, $validated['media']);
            }

            // Update metadata
            $content->setMetadata([
                'updated_by' => $this->security->getCurrentUser()->id,
                'updated_at' => now(),
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent()
            ]);

            DB::commit();

            // Clear relevant cache
            $this->cache->tags(['content', "content:{$id}"])->flush();

            return $content;

        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function delete(int $id): bool
    {
        // Load content
        $content = $this->repository->find($id);
        if (!$content) {
            throw new ContentNotFoundException("Content not found: {$id}");
        }

        // Security check
        $this->security->checkPermission('content.delete', $content);

        DB::beginTransaction();

        try {
            // Soft delete content
            $this->repository->softDelete($id);

            // Record deletion metadata
            $content->setMetadata([
                'deleted_by' => $this->security->getCurrentUser()->id,
                'deleted_at' => now(),
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent()
            ]);

            DB::commit();

            // Clear relevant cache
            $this->cache->tags(['content', "content:{$id}"])->flush();

            return true;

        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function handleMediaAttachments(Content $content, array $media): void
    {
        foreach ($media as $item) {
            // Validate media item
            $this->validator->validate($item, [
                'type' => 'required|in:image,video,document',
                'file' => 'required|file',
                'title' => 'string|max:255'
            ]);

            // Process and attach media
            $this->repository->attachMedia($content->id, $item);
        }
    }
}
