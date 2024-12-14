<?php

namespace App\Core\Repositories;

use App\Core\Repositories\Contracts\VersionRepositoryInterface;
use App\Models\Version;
use Illuminate\Support\Collection;
use App\Services\DiffGenerator;

class VersionRepository extends BaseRepository implements VersionRepositoryInterface
{
    protected DiffGenerator $diffGenerator;

    public function __construct(Version $model, DiffGenerator $diffGenerator)
    {
        parent::__construct($model);
        $this->diffGenerator = $diffGenerator;
    }

    public function createVersion(
        string $type,
        int $entityId,
        array $data,
        ?int $userId = null
    ): Version {
        $previousVersion = $this->getLatestVersion($type, $entityId);
        
        return $this->create([
            'versionable_type' => $type,
            'versionable_id' => $entityId,
            'user_id' => $userId ?? auth()->id(),
            'data' => $data,
            'version_number' => $previousVersion ? $previousVersion->version_number + 1 : 1,
            'diff' => $previousVersion ? $this->generateDiff($previousVersion->data, $data) : null,
            'metadata' => $this->generateMetadata($previousVersion, $data)
        ]);
    }

    public function getVersionHistory(string $type, int $entityId): Collection
    {
        return $this->model
            ->where('versionable_type', $type)
            ->where('versionable_id', $entityId)
            ->with('user')
            ->orderByDesc('version_number')
            ->get();
    }

    public function getVersion(string $type, int $entityId, int $versionNumber): ?Version
    {
        return $this->model
            ->where('versionable_type', $type)
            ->where('versionable_id', $entityId)
            ->where('version_number', $versionNumber)
            ->first();
    }

    public function getLatestVersion(string $type, int $entityId): ?Version
    {
        return $this->model
            ->where('versionable_type', $type)
            ->where('versionable_id', $entityId)
            ->orderByDesc('version_number')
            ->first();
    }

    public function compareVersions(int $versionId1, int $versionId2): array
    {
        $version1 = $this->find($versionId1);
        $version2 = $this->find($versionId2);

        if (!$version1 || !$version2) {
            throw new \InvalidArgumentException('Invalid version IDs');
        }

        return $this->diffGenerator->compare($version1->data, $version2->data);
    }

    public function revertToVersion(string $type, int $entityId, int $versionNumber): ?Version
    {
        $version = $this->getVersion($type, $entityId, $versionNumber);
        if (!$version) {
            return null;
        }

        return $this->createVersion($type, $entityId, $version->data, null, [
            'reverted_from' => $versionNumber
        ]);
    }

    public function pruneVersions(string $type, int $entityId, int $keepLast = 10): int
    {
        $versions = $this->getVersionHistory($type, $entityId);
        
        if ($versions->count() <= $keepLast) {
            return 0;
        }

        $toDelete = $versions->slice($keepLast);
        return $this->model
            ->whereIn('id', $toDelete->pluck('id'))
            ->delete();
    }

    protected function generateDiff(array $oldData, array $newData): array
    {
        return $this->diffGenerator->generate($oldData, $newData);
    }

    protected function generateMetadata(?Version $previousVersion, array $newData): array
    {
        return [
            'timestamp' => now()->toIso8601String(),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'previous_version' => $previousVersion?->version_number,
            'changes_summary' => $this->generateChangesSummary($previousVersion?->data, $newData)
        ];
    }

    protected function generateChangesSummary(?array $oldData, array $newData): array
    {
        if (!$oldData) {
            return ['type' => 'initial_version'];
        }

        $changes = [
            'modified_fields' => [],
            'added_fields' => [],
            'removed_fields' => []
        ];

        foreach ($newData as $key => $value) {
            if (!isset($oldData[$key])) {
                $changes['added_fields'][] = $key;
            } elseif ($oldData[$key] !== $value) {
                $changes['modified_fields'][] = $key;
            }
        }

        foreach ($oldData as $key => $value) {
            if (!isset($newData[$key])) {
                $changes['removed_fields'][] = $key;
            }
        }

        return $changes;
    }
}
