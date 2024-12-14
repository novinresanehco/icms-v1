<?php

namespace App\Core\CMS;

use App\Core\Security\SecurityManager;
use App\Core\Auth\AuthenticationSystem;
use App\Core\Services\{CacheManager, ValidationService, MediaService};
use App\Core\CMS\Exceptions\{ContentException, ValidationException};

class ContentManagementSystem
{
    private SecurityManager $security;
    private AuthenticationSystem $auth;
    private CacheManager $cache;
    private ValidationService $validator;
    private MediaService $media;

    public function __construct(
        SecurityManager $security,
        AuthenticationSystem $auth,
        CacheManager $cache,
        ValidationService $validator,
        MediaService $media
    ) {
        $this->security = $security;
        $this->auth = $auth;
        $this->cache = $cache;
        $this->validator = $validator;
        $this->media = $media;
    }

    public function createContent(array $data, User $author): Content
    {
        return $this->security->executeCriticalOperation(
            new CreateContentOperation($data, $author),
            function() use ($data, $author) {
                // Validate content data
                $this->validateContentData($data);
                
                // Create content with version control
                $content = new Content([
                    'title' => $data['title'],
                    'slug' => $this->generateUniqueSlug($data['title']),
                    'content' => $data['content'],
                    'status' => $data['status'] ?? ContentStatus::DRAFT,
                    'author_id' => $author->id,
                    'version' => 1
                ]);

                $content->save();

                // Handle media attachments
                if (!empty($data['media'])) {
                    $this->processMediaAttachments($content, $data['media']);
                }

                // Handle categories and tags
                $this->processCategories($content, $data['categories'] ?? []);
                $this->processTags($content, $data['tags'] ?? []);

                // Clear relevant caches
                $this->clearContentCaches($content);

                return $content;
            }
        );
    }

    public function updateContent(int $id, array $data, User $editor): Content
    {
        return $this->security->executeCriticalOperation(
            new UpdateContentOperation($id, $data, $editor),
            function() use ($id, $data, $editor) {
                $content = Content::findOrFail($id);
                
                // Verify edit permissions
                if (!$this->canEdit($editor, $content)) {
                    throw new ContentException('Unauthorized content edit attempt');
                }

                // Create new version
                $newVersion = $this->createContentVersion($content);

                // Update content
                $content->update([
                    'title' => $data['title'] ?? $content->title,
                    'content' => $data['content'] ?? $content->content,
                    'status' => $data['status'] ?? $content->status,
                    'editor_id' => $editor->id,
                    'version' => $newVersion->version
                ]);

                // Update relationships if needed
                if (isset($data['media'])) {
                    $this->processMediaAttachments($content, $data['media']);
                }

                if (isset($data['categories'])) {
                    $this->processCategories($content, $data['categories']);
                }

                if (isset($data['tags'])) {
                    $this->processTags($content, $data['tags']);
                }

                // Clear caches
                $this->clearContentCaches($content);

                return $content->fresh();
            }
        );
    }

    public function deleteContent(int $id, User $user): bool
    {
        return $this->security->executeCriticalOperation(
            new DeleteContentOperation($id, $user),
            function() use ($id, $user) {
                $content = Content::findOrFail($id);
                
                if (!$this->canDelete($user, $content)) {
                    throw new ContentException('Unauthorized content deletion attempt');
                }

                // Create deletion record
                ContentDeletion::create([
                    'content_id' => $content->id,
                    'deleted_by' => $user->id,
                    'content_data' => json_encode($content->toArray())
                ]);

                // Remove relationships
                $content->categories()->detach();
                $content->tags()->detach();
                $content->media()->detach();

                // Delete content
                $content->delete();

                // Clear caches
                $this->clearContentCaches($content);

                return true;
            }
        );
    }

    private function validateContentData(array $data): void
    {
        $rules = [
            'title' => 'required|min:3|max:255',
            'content' => 'required',
            'status' => 'in:draft,published,archived',
            'categories' => 'array',
            'tags' => 'array',
            'media' => 'array'
        ];

        if (!$this->validator->validate($data, $rules)) {
            throw new ValidationException('Invalid content data');
        }
    }

    private function createContentVersion(Content $content): ContentVersion
    {
        return ContentVersion::create([
            'content_id' => $content->id,
            'title' => $content->title,
            'content' => $content->content,
            'version' => $content->version + 1,
            'created_by' => $content->editor_id ?? $content->author_id
        ]);
    }

    private function processMediaAttachments(Content $content, array $mediaIds): void
    {
        // Verify media exists and is accessible
        foreach ($mediaIds as $mediaId) {
            if (!$this->media->exists($mediaId)) {
                throw new ContentException("Invalid media ID: {$mediaId}");
            }
        }

        $content->media()->sync($mediaIds);
    }

    private function processCategories(Content $content, array $categories): void
    {
        $categoryIds = Category::whereIn('id', $categories)
            ->where('status', 'active')
            ->pluck('id');

        $content->categories()->sync($categoryIds);
    }

    private function processTags(Content $content, array $tags): void
    {
        $tagIds = collect($tags)->map(function($tag) {
            return Tag::firstOrCreate(['name' => $tag])->id;
        });

        $content->tags()->sync($tagIds);
    }

    private function canEdit(User $user, Content $content): bool
    {
        return $user->id === $content->author_id || 
               $user->hasPermission('edit_all_content');
    }

    private function canDelete(User $user, Content $content): bool
    {
        return $user->id === $content->author_id || 
               $user->hasPermission('delete_all_content');
    }

    private function clearContentCaches(Content $content): void
    {
        $this->cache->tags(['content'])->flush();
        $this->cache->forget("content:{$content->id}");
        $this->cache->forget("content:slug:{$content->slug}");
    }

    private function generateUniqueSlug(string $title): string
    {
        $slug = str_slug($title);
        $count = 1;

        while (Content::where('slug', $slug)->exists()) {
            $slug = str_slug($title) . '-' . $count++;
        }

        return $slug;
    }
}
