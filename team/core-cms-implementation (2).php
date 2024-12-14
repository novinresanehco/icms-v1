<?php

namespace App\Core\Content;

use App\Core\Security\SecurityManager;
use App\Core\Cache\CacheManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ContentManager implements ContentManagerInterface 
{
    private SecurityManager $security;
    private CacheManager $cache;
    private ValidationService $validator;

    public function __construct(
        SecurityManager $security,
        CacheManager $cache,
        ValidationService $validator
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->validator = $validator;
    }

    public function create(array $data): Content 
    {
        // Start transaction
        DB::beginTransaction();
        
        try {
            // Validate input
            $validated = $this->validator->validate($data, [
                'title' => 'required|max:200',
                'content' => 'required',
                'status' => 'required|in:draft,published'
            ]);

            // Security check
            $this->security->validateAccess('content.create');

            // Create content
            $content = Content::create([
                'title' => $validated['title'],
                'content' => $validated['content'],
                'status' => $validated['status'],
                'author_id' => auth()->id(),
                'version' => 1
            ]);

            // Create initial version
            ContentVersion::create([
                'content_id' => $content->id,
                'version' => 1,
                'data' => $validated,
                'created_by' => auth()->id()
            ]);

            // Clear cache
            $this->cache->tags(['content'])->flush();

            // Commit transaction
            DB::commit();

            // Log creation
            Log::info('Content created', ['id' => $content->id]);

            return $content;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Content creation failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function update(int $id, array $data): Content
    {
        DB::beginTransaction();

        try {
            // Load content
            $content = Content::findOrFail($id);

            // Security check
            $this->security->validateAccess('content.update', $content);

            // Validate input
            $validated = $this->validator->validate($data, [
                'title' => 'required|max:200',
                'content' => 'required',
                'status' => 'required|in:draft,published'
            ]);

            // Create new version
            $newVersion = $content->version + 1;
            ContentVersion::create([
                'content_id' => $content->id,
                'version' => $newVersion,
                'data' => $validated,
                'created_by' => auth()->id()
            ]);

            // Update content
            $content->update([
                'title' => $validated['title'],
                'content' => $validated['content'],
                'status' => $validated['status'],
                'version' => $newVersion
            ]);

            // Clear cache
            $this->cache->tags(['content'])->flush();

            DB::commit();

            Log::info('Content updated', ['id' => $id]);

            return $content;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Content update failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function delete(int $id): bool
    {
        DB::beginTransaction();

        try {
            $content = Content::findOrFail($id);

            // Security check
            $this->security->validateAccess('content.delete', $content);

            // Soft delete content
            $content->delete();

            // Clear cache
            $this->cache->tags(['content'])->flush();

            DB::commit();

            Log::info('Content deleted', ['id' => $id]);

            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Content deletion failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function publish(int $id): bool
    {
        DB::beginTransaction();

        try {
            $content = Content::findOrFail($id);

            // Security check
            $this->security->validateAccess('content.publish', $content);

            // Validate ready for publish
            if (!$this->validator->validatePublishState($content)) {
                throw new \Exception('Content not ready for publishing');
            }

            $content->update([
                'status' => 'published',
                'published_at' => now()
            ]);

            // Clear cache
            $this->cache->tags(['content'])->flush();

            DB::commit();

            Log::info('Content published', ['id' => $id]);

            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Content publish failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function revertToVersion(int $id, int $version): Content
    {
        DB::beginTransaction();

        try {
            $content = Content::findOrFail($id);

            // Security check  
            $this->security->validateAccess('content.revert', $content);

            // Get version data
            $versionData = ContentVersion::where('content_id', $id)
                ->where('version', $version)
                ->firstOrFail()
                ->data;

            // Create new version
            $newVersion = $content->version + 1;
            ContentVersion::create([
                'content_id' => $content->id,
                'version' => $newVersion,
                'data' => $versionData,
                'created_by' => auth()->id(),
                'reverted_from' => $version
            ]);

            // Update content
            $content->update([
                'title' => $versionData['title'],
                'content' => $versionData['content'],
                'version' => $newVersion
            ]);

            // Clear cache
            $this->cache->tags(['content'])->flush();

            DB::commit();

            Log::info('Content reverted', [
                'id' => $id,
                'from_version' => $version,
                'new_version' => $newVersion
            ]);

            return $content;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Content revert failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }
}
