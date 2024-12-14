<?php

namespace App\Core\Repository;

use App\Models\Revision;
use App\Core\Events\RevisionEvents;
use App\Core\Exceptions\RevisionRepositoryException;

class RevisionRepository extends BaseRepository
{
    protected function getModelClass(): string
    {
        return Revision::class;
    }

    /**
     * Create new revision
     */
    public function createRevision(string $entityType, int $entityId, array $data): Revision
    {
        try {
            DB::beginTransaction();

            // Get current version number
            $currentVersion = $this->getCurrentVersion($entityType, $entityId);
            $newVersion = $currentVersion + 1;

            // Create revision record
            $revision = $this->create([
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'version' => $newVersion,
                'data' => $data,
                'created_by' => auth()->id(),
                'created_at' => now()
            ]);

            DB::commit();
            $this->clearCache();
            event(new RevisionEvents\RevisionCreated($revision));

            return $revision;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new RevisionRepositoryException(
                "Failed to create revision: {$e->getMessage()}"
            );
        }
    }

    /**
     * Get revision history
     */
    public function getRevisionHistory(string $entityType, int $entityId): Collection
    {
        return Cache::tags($this->getCacheTags())->remember(
            $this->getCacheKey("history.{$entityType}.{$entityId}"),
            $this->cacheTime,
            fn() => $this->model
                ->where('entity_type', $entityType)
                ->where('entity_id', $entityId)
                ->with('creator')
                ->orderByDesc('version')
                ->get()
        );
    }

    /**
     * Get specific revision
     */
    public function getRevision(string $entityType, int $entityId, int $version): ?Revision
    {
        return Cache::tags($this->getCacheTags())->remember(
            $this->getCacheKey("revision.{$entityType}.{$entityId}.{$version}"),
            $this->cacheTime,
            fn() => $this->model
                ->where('entity_type', $entityType)
                ->where('entity_id', $entityId)
                ->where('version', $version)
                ->first()
        );
    }

    /**
     * Compare revisions
     */
    public function compareRevisions(Revision $from, Revision $to): array
    {
        try {
            return array_merge(
                $this->compareData($from->data, $to->data),
                [
                    'from_version' => $from->version,
                    'to_version' => $to->version,
                    'from_date' => $from->created_at,
                    'to_date' => $to->created_at
                ]
            );
        } catch (\Exception $e) {
            throw new RevisionRepositoryException(
                "Failed to compare revisions: {$e->getMessage()}"
            );
        }
    }

    /**
     * Restore to revision
     */
    public function restoreToRevision(string $entityType, int $entityId, int $version): void
    {
        try {
            DB::beginTransaction();

            $revision = $this->getRevision($entityType, $entityId, $version);
            if (!$revision) {
                throw new RevisionRepositoryException("Revision not found");
            }

            // Create new revision with restored data
            $this->createRevision($entityType, $entityId, $revision->data);

            DB::commit();
            event(new RevisionEvents\RevisionRestored($revision));

        } catch (\Exception $e) {
            DB::rollBack();
            throw new RevisionRepositoryException(
                "Failed to restore revision: {$e->getMessage()}"
            );
        }
    }

    /**
     * Get current version number
     */
    protected function getCurrentVersion(string $entityType, int $entityId): int
    {
        return $this->model
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->max('version') ?? 0;
    }

    /**
     * Compare revision data
     */
    protected function compareData(array $fromData, array $toData): array
    {
        $changes = [];
        
        foreach ($toData as $key => $value) {
            if (!isset($fromData[$key])) {
                $changes['added'][$key] = $value;
            } elseif ($fromData[$key] !== $value) {
                $changes['modified'][$key] = [
                    'from' => $fromData[$key],
                    'to' => $value
                ];
            }
        }

        foreach ($fromData as $key => $value) {
            if (!isset($toData[$key])) {
                $changes['removed'][$key] = $value;
            }
        }

        return $changes;
    }
}
