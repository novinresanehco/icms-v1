<?php

namespace App\Core\Repository;

use App\Models\ContentRevision;
use App\Core\Events\ContentRevisionEvents;
use App\Core\Exceptions\ContentRevisionException;

class ContentRevisionRepository extends BaseRepository
{
    protected function getModelClass(): string
    {
        return ContentRevision::class;
    }

    /**
     * Create revision
     */
    public function createRevision(int $contentId, array $data): ContentRevision
    {
        try {
            DB::beginTransaction();

            // Get current version number
            $currentVersion = $this->getCurrentVersion($contentId);
            $version = $currentVersion + 1;

            $revision = $this->create([
                'content_id' => $contentId,
                'version' => $version,
                'title' => $data['title'],
                'content' => $data['content'],
                'metadata' => $data['metadata'] ?? [],
                'editor_id' => auth()->id(),
                'summary' => $data['summary'] ?? 'Content updated',
                'created_at' => now()
            ]);

            // Store additional revision data
            $this->storeRevisionData($revision, $data);

            DB::commit();
            event(new ContentRevisionEvents\RevisionCreated($revision));

            return $revision;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new ContentRevisionException(
                "Failed to create revision: {$e->getMessage()}"
            );
        }
    }

    /**
     * Get revision history
     */
    public function getRevisionHistory(int $contentId): Collection
    {
        return Cache::tags($this->getCacheTags())->remember(
            $this->getCacheKey("history.{$contentId}"),
            $this->cacheTime,
            fn() => $this->model
                ->where('content_id', $contentId)
                ->with('editor')
                ->orderByDesc('version')
                ->get()
        );
    }

    /**
     * Compare revisions
     */
    public function compareRevisions(int $revisionId1, int $revisionId2): array
    {
        try {
            $revision1 = $this->find($revisionId1);
            $revision2 = $this->find($revisionId2);

            if (!$revision1 || !$revision2) {
                throw new ContentRevisionException("One or both revisions not found");
            }

            return [
                'title' => $this->compareText($revision1->title, $revision2->title),
                'content' => $this->compareContent($revision1->content, $revision2->content),
                'metadata' => $this->compareMetadata($revision1->metadata, $revision2->metadata)
            ];

        } catch (\Exception $e) {
            throw new ContentRevisionException(
                "Failed to compare revisions: {$e->getMessage()}"
            );
        }
    }

    /**
     * Restore revision
     */
    public function restoreRevision(int $revisionId): void
    {
        try {
            DB::beginTransaction();

            $revision = $this->find($revisionId);
            if (!$revision) {
                throw new ContentRevisionException("Revision not found with ID: {$revisionId}");
            }

            // Update content with revision data
            $content = $revision->content;
            $content->update([
                'title' => $revision->title,
                'content' => $revision->content,
                'metadata' => $revision->metadata,
                'updated_at' => now(),
                'updated_by' => auth()->id()
            ]);

            // Create new revision for restore action
            $this->createRevision($content->id, [
                'title' => $revision->title,
                'content' => $revision->content,
                'metadata' => $revision->metadata,
                'summary' => "Restored from version {$revision->version}"
            ]);

            DB::commit();
            event(new ContentRevisionEvents\RevisionRestored($revision));

        } catch (\Exception $e) {
            DB::rollBack();
            throw new ContentRevisionException(
                "Failed to restore revision: {$e->getMessage()}"
            );
        }
    }

    /**
     * Get current version number
     */
    protected function getCurrentVersion(int $contentId): int
    {
        return $this->model
            ->where('content_id', $contentId)
            ->max('version') ?? 0;
    }

    /**
     * Store additional revision data
     */
    protected function storeRevisionData(ContentRevision $revision, array $data): void
    {
        // Store related data like tags, categories, etc.
        if (!empty($data['tags'])) {
            $revision->tags()->sync($data['tags']);
        }

        if (!empty($data['assets'])) {
            $revision->assets()->sync($data['assets']);
        }
    }

    /**
     * Compare text content
     */
    protected function compareText(string $text1, string $text2): array
    {
        $diff = new Diff(
            explode("\n", $text1),
            explode("\n", $text2),
            ['context' => 3]
        );

        return [
            'changes' => $diff->getChanges(),
            'has_changes' => !$diff->isEmpty()
        ];
    }

    /**
     * Compare structured content
     */
    protected function compareContent(array $content1, array $content2): array
    {
        $changes = [];
        $allKeys = array_unique(array_merge(array_keys($content1), array_keys($content2)));

        foreach ($allKeys as $key) {
            if (!isset($content1[$key])) {
                $changes[$key] = ['type' => 'added', 'value' => $content2[$key]];
            } elseif (!isset($content2[$key])) {
                $changes[$key] = ['type' => 'removed', 'value' => $content1[$key]];
            } elseif ($content1[$key] !== $content2[$key]) {
                $changes[$key] = [
                    'type' => 'modified',
                    'old' => $content1[$key],
                    'new' => $content2[$key]
                ];
            }
        }

        return $changes;
    }

    /**
     * Compare metadata
     */
    protected function compareMetadata(array $metadata1, array $metadata2): array
    {
        // Similar to compareContent but specifically for metadata structure
        return $this->compareContent($metadata1, $metadata2);
    }
}
