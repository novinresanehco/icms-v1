<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Revision;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use App\Repositories\Interfaces\RevisionRepositoryInterface;

class RevisionRepository implements RevisionRepositoryInterface
{
    private const CACHE_PREFIX = 'revision:';
    private const CACHE_TTL = 3600;

    public function __construct(
        private readonly Revision $model
    ) {}

    public function findById(int $id): ?Revision
    {
        return Cache::remember(
            self::CACHE_PREFIX . $id,
            self::CACHE_TTL,
            fn () => $this->model->find($id)
        );
    }

    public function create(array $data): Revision
    {
        $revision = $this->model->create([
            'content_id' => $data['content_id'],
            'user_id' => $data['user_id'],
            'title' => $data['title'],
            'content' => $data['content'],
            'metadata' => $data['metadata'] ?? [],
            'summary' => $data['summary'] ?? null,
            'version' => $this->getNextVersion($data['content_id'])
        ]);

        $this->clearCache($revision);

        return $revision;
    }

    public function getByContent(int $contentId): Collection
    {
        return Cache::remember(
            self::CACHE_PREFIX . "content:{$contentId}",
            self::CACHE_TTL,
            fn () => $this->model->where('content_id', $contentId)
                ->orderBy('version', 'desc')
                ->get()
        );
    }

    public function getLatestByContent(int $contentId): ?Revision
    {
        return Cache::remember(
            self::CACHE_PREFIX . "content:{$contentId}:latest",
            self::CACHE_TTL,
            fn () => $this->model->where('content_id', $contentId)
                ->orderBy('version', 'desc')
                ->first()
        );
    }

    public function compareVersions(int $fromId, int $toId): array
    {
        $fromRevision = $this->findById($fromId);
        $toRevision = $this->findById($toId);

        if (!$fromRevision || !$toRevision) {
            return [];
        }

        return [
            'title' => [
                'from' => $fromRevision->title,
                'to' => $toRevision->title,
                'changed' => $fromRevision->title !== $toRevision->title
            ],
            'content' => [
                'from' => $fromRevision->content,
                'to' => $toRevision->content,
                'changed' => $fromRevision->content !== $toRevision->content
            ],
            'metadata' => [
                'from' => $fromRevision->metadata,
                'to' => $toRevision->metadata,
                'changed' => $fromRevision->metadata !== $toRevision->metadata
            ],
            'timestamp' => [
                'from' => $fromRevision->created_at,
                'to' => $toRevision->created_at
            ],
            'users' => [
                'from' => $fromRevision->user_id,
                'to' => $toRevision->user_id
            ]
        ];
    }

    public function revertTo(int $contentId, int $versionNumber): bool
    {
        $revision = $this->model->where('content_id', $contentId)
            ->where('version', $versionNumber)
            ->first();

        if (!$revision) {
            return false;
        }

        // Create new revision with old content
        $this->create([
            'content_id' => $contentId,
            'user_id' => auth()->id(),
            'title' => $revision->title,
            'content' => $revision->content,
            'metadata' => $revision->metadata,
            'summary' => "Reverted to version {$versionNumber}"
        ]);

        return true;
    }

    public function pruneRevisions(int $contentId, int $keepLast = 10): bool
    {
        $revisions = $this->model->where('content_id', $contentId)
            ->orderBy('version', 'desc')
            ->get();

        if ($revisions->count() <= $keepLast) {
            return false;
        }

        $revisionsToDelete = $revisions->slice($keepLast);
        foreach ($revisionsToDelete as $revision) {
            $revision->delete();
        }

        $this->clearCache($revisions->first());

        return true;
    }

    protected function getNextVersion(int $contentId): int
    {
        $lastVersion = $this->model->where('content_id', $contentId)
            ->max('version');

        return ($lastVersion ?? 0) + 1;
    }

    protected function clearCache(Revision $revision): void
    {
        Cache::forget(self::CACHE_PREFIX . $revision->id);
        Cache::forget(self::CACHE_PREFIX . "content:{$revision->content_id}");
        Cache::forget(self::CACHE_PREFIX . "content:{$revision->content_id}:latest");
    }
}