<?php

namespace App\Core\Content;

use App\Core\Security\SecurityManager;
use App\Core\Validation\ValidationService;
use App\Core\Cache\CacheManager;
use App\Core\Interfaces\ContentManagerInterface;
use Illuminate\Support\Facades\DB;

class ContentManager implements ContentManagerInterface 
{
    private SecurityManager $security;
    private ValidationService $validator;
    private CacheManager $cache;
    private ContentRepository $repository;
    private MediaManager $media;
    
    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        CacheManager $cache,
        ContentRepository $repository,
        MediaManager $media
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->cache = $cache;
        $this->repository = $repository;
        $this->media = $media;
    }

    public function create(array $data): Content 
    {
        return $this->security->executeCriticalOperation(
            new CreateContentOperation($data, $this->repository),
            new SecurityContext([
                'operation' => 'content.create',
                'data' => $data,
                'user' => auth()->user()
            ])
        );
    }

    public function update(int $id, array $data): Content 
    {
        return $this->security->executeCriticalOperation(
            new UpdateContentOperation($id, $data, $this->repository),
            new SecurityContext([
                'operation' => 'content.update',
                'contentId' => $id,
                'data' => $data,
                'user' => auth()->user()
            ])
        );
    }

    public function delete(int $id): bool 
    {
        return $this->security->executeCriticalOperation(
            new DeleteContentOperation($id, $this->repository),
            new SecurityContext([
                'operation' => 'content.delete',
                'contentId' => $id,
                'user' => auth()->user()
            ])
        );
    }

    public function publish(int $id): bool 
    {
        return $this->security->executeCriticalOperation(
            new PublishContentOperation($id, $this->repository),
            new SecurityContext([
                'operation' => 'content.publish',
                'contentId' => $id,
                'user' => auth()->user()
            ])
        );
    }

    public function version(int $id): ContentVersion 
    {
        return $this->security->executeCriticalOperation(
            new VersionContentOperation($id, $this->repository),
            new SecurityContext([
                'operation' => 'content.version',
                'contentId' => $id,
                'user' => auth()->user()
            ])
        );
    }

    public function attachMedia(int $contentId, array $mediaIds): void 
    {
        $this->security->executeCriticalOperation(
            new AttachMediaOperation($contentId, $mediaIds, $this->repository, $this->media),
            new SecurityContext([
                'operation' => 'content.attachMedia',
                'contentId' => $contentId,
                'mediaIds' => $mediaIds,
                'user' => auth()->user()
            ])
        );
    }

    public function getById(int $id): ?Content 
    {
        return $this->cache->remember(
            "content.$id",
            fn() => $this->repository->findWithMedia($id)
        );
    }

    public function getVersions(int $id): array 
    {
        return $this->cache->remember(
            "content.$id.versions",
            fn() => $this->repository->getVersions($id)
        );
    }

    public function restore(int $id, int $versionId): Content 
    {
        return $this->security->executeCriticalOperation(
            new RestoreContentOperation($id, $versionId, $this->repository),
            new SecurityContext([
                'operation' => 'content.restore',
                'contentId' => $id,
                'versionId' => $versionId,
                'user' => auth()->user()
            ])
        );
    }
}

class ContentRepository
{
    public function findWithMedia(int $id): ?Content
    {
        return DB::transaction(function() use ($id) {
            $content = Content::with('media')->findOrFail($id);
            return $content->isAccessibleBy(auth()->user()) ? $content : null;
        });
    }

    public function getVersions(int $id): array
    {
        return DB::transaction(function() use ($id) {
            return ContentVersion::where('content_id', $id)
                ->orderBy('created_at', 'desc')
                ->get()
                ->toArray();
        });
    }

    public function create(array $data): Content
    {
        return DB::transaction(function() use ($data) {
            $content = new Content($data);
            $content->save();
            $this->createVersion($content);
            return $content;
        });
    }

    public function update(Content $content, array $data): Content
    {
        return DB::transaction(function() use ($content, $data) {
            $content->update($data);
            $this->createVersion($content);
            return $content;
        });
    }

    private function createVersion(Content $content): ContentVersion
    {
        $version = new ContentVersion([
            'content_id' => $content->id,
            'data' => $content->toArray(),
            'created_by' => auth()->id()
        ]);
        $version->save();
        return $version;
    }
}

class CreateContentOperation extends CriticalOperation
{
    private array $data;
    private ContentRepository $repository;

    public function execute(): Content
    {
        return $this->repository->create($this->data);
    }

    public function getRules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'status' => 'required|in:draft,published'
        ];
    }
}

class UpdateContentOperation extends CriticalOperation 
{
    private int $id;
    private array $data;
    private ContentRepository $repository;

    public function execute(): Content
    {
        $content = Content::findOrFail($this->id);
        return $this->repository->update($content, $this->data);
    }
}

class PublishContentOperation extends CriticalOperation
{
    private int $id;
    private ContentRepository $repository;

    public function execute(): bool
    {
        return DB::transaction(function() {
            $content = Content::findOrFail($this->id);
            $content->status = 'published';
            $content->published_at = now();
            $content->save();
            $this->repository->createVersion($content);
            return true;
        });
    }
}

class AttachMediaOperation extends CriticalOperation
{
    private int $contentId;
    private array $mediaIds;
    private ContentRepository $repository;
    private MediaManager $media;

    public function execute(): void
    {
        DB::transaction(function() {
            $content = Content::findOrFail($this->contentId);
            $media = Media::findMany($this->mediaIds);
            
            // Verify media exists and is accessible
            if ($media->count() !== count($this->mediaIds)) {
                throw new \Exception('Invalid media IDs');
            }

            $content->media()->sync($this->mediaIds);
            $this->repository->createVersion($content);
        });
    }
}
