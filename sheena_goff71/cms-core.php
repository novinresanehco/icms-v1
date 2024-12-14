<?php

namespace App\Core\CMS;

use Illuminate\Support\Facades\{DB, Cache};
use App\Core\Security\SecurityManagerInterface;
use App\Core\Services\{ValidationService, MediaManager};
use App\Core\Events\ContentEvent;
use App\Core\Exceptions\{ContentException, ValidationException};

class ContentManager implements ContentManagerInterface
{
    private SecurityManagerInterface $security;
    private ValidationService $validator;
    private MediaManager $mediaManager;
    private CategoryRepository $categories;
    private VersionManager $versions;

    public function __construct(
        SecurityManagerInterface $security,
        ValidationService $validator,
        MediaManager $mediaManager,
        CategoryRepository $categories,
        VersionManager $versions
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->mediaManager = $mediaManager;
        $this->categories = $categories;
        $this->versions = $versions;
    }

    public function createContent(array $data, User $user): Content
    {
        return $this->security->executeCriticalOperation(function() use ($data, $user) {
            // Validate content data
            $validated = $this->validator->validate($data, [
                'title' => 'required|max:255',
                'content' => 'required',
                'status' => 'required|in:draft,published,archived',
                'category_id' => 'required|exists:categories,id',
                'meta' => 'array'
            ]);

            DB::beginTransaction();
            try {
                // Create content
                $content = new Content($validated);
                $content->user_id = $user->id;
                $content->save();

                // Handle media attachments
                if (!empty($data['media'])) {
                    $this->mediaManager->attachMedia($content, $data['media']);
                }

                // Create initial version
                $this->versions->createVersion($content, $user);

                // Process categories
                $this->categories->attachCategories($content, $data['category_id']);

                DB::commit();

                event(new ContentEvent('created', $content));
                Cache::tags(['content'])->flush();

                return $content;

            } catch (\Exception $e) {
                DB::rollBack();
                throw new ContentException('Failed to create content: ' . $e->getMessage());
            }
        });
    }

    public function updateContent(int $id, array $data, User $user): Content
    {
        return $this->security->executeCriticalOperation(function() use ($id, $data, $user) {
            $content = Content::findOrFail($id);
            
            // Create new version before update
            $this->versions->createVersion($content, $user);

            $validated = $this->validator->validate($data, [
                'title' => 'sometimes|max:255',
                'content' => 'sometimes',
                'status' => 'sometimes|in:draft,published,archived',
                'category_id' => 'sometimes|exists:categories,id',
                'meta' => 'sometimes|array'
            ]);

            DB::beginTransaction();
            try {
                $content->fill($validated);
                $content->save();

                if (isset($data['media'])) {
                    $this->mediaManager->syncMedia($content, $data['media']);
                }

                if (isset($data['category_id'])) {
                    $this->categories->syncCategories($content, $data['category_id']);
                }

                DB::commit();

                event(new ContentEvent('updated', $content));
                Cache::tags(['content', "content:{$id}"])->flush();

                return $content;

            } catch (\Exception $e) {
                DB::rollBack();
                throw new ContentException('Failed to update content: ' . $e->getMessage());
            }
        });
    }

    public function publishContent(int $id, User $user): Content
    {
        return $this->security->executeCriticalOperation(function() use ($id, $user) {
            $content = Content::findOrFail($id);
            
            if ($content->status === 'published') {
                throw new ContentException('Content is already published');
            }

            DB::beginTransaction();
            try {
                $content->status = 'published';
                $content->published_at = now();
                $content->publisher_id = $user->id;
                $content->save();

                // Create published version
                $this->versions->createVersion($content, $user, 'published');

                DB::commit();

                event(new ContentEvent('published', $content));
                Cache::tags(['content', "content:{$id}"])->flush();

                return $content;

            } catch (\Exception $e) {
                DB::rollBack();
                throw new ContentException('Failed to publish content: ' . $e->getMessage());
            }
        });
    }

    public function deleteContent(int $id, User $user): bool
    {
        return $this->security->executeCriticalOperation(function() use ($id, $user) {
            $content = Content::findOrFail($id);

            DB::beginTransaction();
            try {
                // Create final version before deletion
                $this->versions->createVersion($content, $user, 'deleted');

                // Remove media associations
                $this->mediaManager->detachAllMedia($content);

                // Remove category associations
                $this->categories->detachAllCategories($content);

                // Soft delete the content
                $content->deleted_by = $user->id;
                $content->save();
                $content->delete();

                DB::commit();

                event(new ContentEvent('deleted', $content));
                Cache::tags(['content', "content:{$id}"])->flush();

                return true;

            } catch (\Exception $e) {
                DB::rollBack();
                throw new ContentException('Failed to delete content: ' . $e->getMessage());
            }
        });
    }

    public function restoreVersion(int $id, int $versionId, User $user): Content
    {
        return $this->security->executeCriticalOperation(function() use ($id, $versionId, $user) {
            $content = Content::findOrFail($id);
            $version = $this->versions->getVersion($versionId);

            if ($version->content_id !== $content->id) {
                throw new ValidationException('Invalid version for content');
            }

            DB::beginTransaction();
            try {
                // Create new version before restoration
                $this->versions->createVersion($content, $user, 'pre-restore');

                // Restore content from version
                $content->fill($version->content_data);
                $content->save();

                // Create post-restore version
                $this->versions->createVersion($content, $user, 'restored');

                DB::commit();

                event(new ContentEvent('restored', $content));
                Cache::tags(['content', "content:{$id}"])->flush();

                return $content;

            } catch (\Exception $e) {
                DB::rollBack();
                throw new ContentException('Failed to restore version: ' . $e->getMessage());
            }
        });
    }

    protected function validatePermissions(User $user, string $action, Content $content = null): void
    {
        if (!$this->security->hasPermission($user, "content.{$action}")) {
            throw new UnauthorizedException("User does not have permission to {$action} content");
        }

        if ($content && $action !== 'create') {
            if (!$this->security->canManageContent($user, $content)) {
                throw new UnauthorizedException('User cannot manage this content');
            }
        }
    }
}
