<?php

namespace App\Core\CMS;

use Illuminate\Support\Facades\{DB, Cache, Log};
use App\Core\Security\SecurityManager;
use App\Core\Exceptions\CMSException;

class ContentManager
{
    private SecurityManager $security;
    private ContentRepository $repository;
    private ValidationService $validator;
    private AuditLogger $auditLogger;

    public function createContent(array $data, array $context): ContentEntity
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeCreateContent($data),
            $context
        );
    }

    private function executeCreateContent(array $data): ContentEntity
    {
        $validated = $this->validator->validate($data, [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'status' => 'required|in:draft,published',
            'type' => 'required|string',
            'metadata' => 'array'
        ]);

        return DB::transaction(function() use ($validated) {
            $content = $this->repository->create($validated);
            
            $this->handleMediaAttachments($content, $validated['media'] ?? []);
            $this->updateContentCache($content);
            $this->auditLogger->logContentCreation($content);

            return $content;
        });
    }

    public function updateContent(int $id, array $data, array $context): ContentEntity
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeUpdateContent($id, $data),
            $context
        );
    }

    private function executeUpdateContent(int $id, array $data): ContentEntity
    {
        $validated = $this->validator->validate($data);
        
        return DB::transaction(function() use ($id, $validated) {
            $content = $this->repository->findOrFail($id);
            $this->createRevision($content);
            
            $content = $this->repository->update($id, $validated);
            
            $this->handleMediaAttachments($content, $validated['media'] ?? []);
            $this->invalidateContentCache($content);
            $this->auditLogger->logContentUpdate($content);

            return $content;
        });
    }

    public function publishContent(int $id, array $context): ContentEntity
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executePublishContent($id),
            $context
        );
    }

    private function executePublishContent(int $id): ContentEntity
    {
        return DB::transaction(function() use ($id) {
            $content = $this->repository->findOrFail($id);
            
            if ($content->status === 'published') {
                throw new CMSException('Content already published');
            }

            $this->createRevision($content);
            $content->status = 'published';
            $content->published_at = now();
            $content->save();

            $this->invalidateContentCache($content);
            $this->auditLogger->logContentPublish($content);

            return $content;
        });
    }

    private function createRevision(ContentEntity $content): void
    {
        $revision = new ContentRevision([
            'content_id' => $content->id,
            'data' => $content->toArray(),
            'created_by' => auth()->id()
        ]);

        $revision->save();
        $this->auditLogger->logRevisionCreation($revision);
    }

    private function handleMediaAttachments(ContentEntity $content, array $media): void
    {
        $existingMedia = $content->media->pluck('id')->toArray();
        $mediaToAttach = array_diff($media, $existingMedia);
        $mediaToDetach = array_diff($existingMedia, $media);

        if (!empty($mediaToDetach)) {
            $content->media()->detach($mediaToDetach);
            $this->auditLogger->logMediaDetachment($content, $mediaToDetach);
        }

        if (!empty($mediaToAttach)) {
            $content->media()->attach($mediaToAttach);
            $this->auditLogger->logMediaAttachment($content, $mediaToAttach);
        }
    }

    private function updateContentCache(ContentEntity $content): void
    {
        $cacheKey = $this->getCacheKey($content->id);
        Cache::tags(['content'])->put($cacheKey, $content, now()->addDay());
    }

    private function invalidateContentCache(ContentEntity $content): void
    {
        Cache::tags(['content'])->forget($this->getCacheKey($content->id));
        Cache::tags(['content'])->forget('content_list');
    }

    private function getCacheKey(int $contentId): string
    {
        return "content:{$contentId}";
    }
}

class ContentRepository
{
    public function create(array $data): ContentEntity
    {
        return ContentEntity::create($data);
    }

    public function update(int $id, array $data): ContentEntity
    {
        $content = $this->findOrFail($id);
        $content->update($data);
        return $content;
    }

    public function findOrFail(int $id): ContentEntity
    {
        return ContentEntity::findOrFail($id);
    }

    public function delete(int $id): bool
    {
        return ContentEntity::destroy($id) > 0;
    }
}

class ContentEntity extends Model
{
    protected $fillable = [
        'title',
        'content',
        'status',
        'type',
        'metadata',
        'published_at'
    ];

    protected $casts = [
        'metadata' => 'array',
        'published_at' => 'datetime'
    ];

    public function media()
    {
        return $this->belongsToMany(Media::class);
    }

    public function revisions()
    {
        return $this->hasMany(ContentRevision::class);
    }
}
