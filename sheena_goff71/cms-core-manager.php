<?php

namespace App\Core\CMS;

use App\Core\Auth\AuthenticationManager;
use App\Core\Security\SecurityManager;
use App\Core\Storage\StorageManager;
use Illuminate\Support\Facades\{DB, Cache};
use Illuminate\Database\Eloquent\Collection;

class ContentManager
{
    private SecurityManager $security;
    private StorageManager $storage;
    private AuthenticationManager $auth;
    
    public function __construct(
        SecurityManager $security,
        StorageManager $storage,
        AuthenticationManager $auth
    ) {
        $this->security = $security;
        $this->storage = $storage;
        $this->auth = $auth;
    }

    public function createContent(array $data, User $user): Content
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->performCreate($data, $user),
            ['action' => 'content_create', 'user' => $user]
        );
    }

    private function performCreate(array $data, User $user): Content
    {
        // Validate content data
        $validated = $this->validateContentData($data);

        // Process any media files
        $mediaIds = $this->processMediaFiles($data['media'] ?? []);

        // Create content record
        $content = DB::transaction(function() use ($validated, $user, $mediaIds) {
            // Create main content
            $content = Content::create([
                'title' => $validated['title'],
                'slug' => $this->generateUniqueSlug($validated['title']),
                'content' => $validated['content'],
                'status' => $validated['status'] ?? 'draft',
                'user_id' => $user->id,
                'type' => $validated['type'],
                'meta' => $validated['meta'] ?? [],
                'version' => 1
            ]);

            // Attach categories
            if (!empty($validated['categories'])) {
                $content->categories()->attach($validated['categories']);
            }

            // Attach media
            if (!empty($mediaIds)) {
                $content->media()->attach($mediaIds);
            }

            // Create content version
            $this->createContentVersion($content);

            return $content;
        });

        // Clear relevant caches
        $this->clearContentCaches($content);

        return $content;
    }

    public function updateContent(int $id, array $data, User $user): Content
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->performUpdate($id, $data, $user),
            ['action' => 'content_update', 'content_id' => $id, 'user' => $user]
        );
    }

    private function performUpdate(int $id, array $data, User $user): Content
    {
        // Load and lock content
        $content = Content::lockForUpdate()->findOrFail($id);
        
        // Verify permissions
        if (!$this->canUserModifyContent($user, $content)) {
            throw new UnauthorizedException('User cannot modify this content');
        }

        // Validate update data
        $validated = $this->validateContentData($data);

        return DB::transaction(function() use ($content, $validated, $user) {
            // Create new version first
            $this->createContentVersion($content);

            // Update content
            $content->update([
                'title' => $validated['title'],
                'content' => $validated['content'],
                'status' => $validated['status'] ?? $content->status,
                'meta' => array_merge($content->meta, $validated['meta'] ?? []),
                'version' => $content->version + 1,
                'updated_by' => $user->id
            ]);

            // Update relationships if needed
            if (isset($validated['categories'])) {
                $content->categories()->sync($validated['categories']);
            }

            if (isset($validated['media'])) {
                $mediaIds = $this->processMediaFiles($validated['media']);
                $content->media()->sync($mediaIds);
            }

            // Clear caches
            $this->clearContentCaches($content);

            return $content->fresh();
        });
    }

    public function getContent(int $id, ?User $user = null): ?Content
    {
        return Cache::remember(
            "content:{$id}",
            3600,
            fn() => $this->loadContent($id, $user)
        );
    }

    private function loadContent(int $id, ?User $user): ?Content
    {
        $content = Content::with(['categories', 'media'])->find($id);
        
        if (!$content || !$this->canUserAccessContent($user, $content)) {
            return null;
        }

        return $content;
    }

    public function deleteContent(int $id, User $user): bool
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->performDelete($id, $user),
            ['action' => 'content_delete', 'content_id' => $id, 'user' => $user]
        );
    }

    private function performDelete(int $id, User $user): bool
    {
        $content = Content::findOrFail($id);
        
        if (!$this->canUserModifyContent($user, $content)) {
            throw new UnauthorizedException('User cannot delete this content');
        }

        DB::transaction(function() use ($content) {
            // Archive content first
            $this->archiveContent($content);
            
            // Then delete
            $content->categories()->detach();
            $content->media()->detach();
            $content->delete();
        });

        // Clear caches
        $this->clearContentCaches($content);

        return true;
    }

    private function validateContentData(array $data): array
    {
        $validator = Validator::make($data, [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'type' => 'required|string|in:page,post,custom',
            'status' => 'nullable|string|in:draft,published,archived',
            'categories' => 'nullable|array',
            'categories.*' => 'exists:categories,id',
            'meta' => 'nullable|array',
            'media' => 'nullable|array',
            'media.*' => 'file|mimes:jpeg,png,pdf|max:10240'
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator->errors());
        }

        return $validator->validated();
    }

    private function generateUniqueSlug(string $title): string
    {
        $slug = Str::slug($title);
        $count = 1;

        while (Content::where('slug', $slug)->exists()) {
            $slug = Str::slug($title) . '-' . $count++;
        }

        return $slug;
    }

    private function processMediaFiles(array $files): array
    {
        return array_map(
            fn($file) => $this->storage->storeMedia($file),
            $files
        );
    }

    private function createContentVersion(Content $content): void
    {
        ContentVersion::create([
            'content_id' => $content->id,
            'version' => $content->version,
            'data' => $content->toArray(),
            'created_by' => $content->updated_by ?? $content->user_id
        ]);
    }

    private function archiveContent(Content $content): void
    {
        ArchivedContent::create([
            'content_id' => $content->id,
            'data' => $content->toArray(),
            'relationships' => [
                'categories' => $content->categories->pluck('id'),
                'media' => $content->media->pluck('id')
            ]
        ]);
    }

    private function clearContentCaches(Content $content): void
    {
        Cache::forget("content:{$content->id}");
        Cache::tags(['content_list'])->flush();
    }

    private function canUserAccessContent(?User $user, Content $content): bool
    {
        if ($content->status === 'published') {
            return true;
        }

        return $user && 
               ($user->id === $content->user_id || 
                $user->hasPermission('view_all_content'));
    }

    private function canUserModifyContent(User $user, Content $content): bool
    {
        return $user->id === $content->user_id || 
               $user->hasPermission('manage_all_content');
    }
}
