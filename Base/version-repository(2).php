<?php

namespace App\Core\Repositories;

use App\Models\Version;
use Illuminate\Support\Collection;

class VersionRepository extends AdvancedRepository
{
    protected $model = Version::class;

    public function createVersion(string $model, int $modelId, array $data): Version
    {
        return $this->executeTransaction(function() use ($model, $modelId, $data) {
            $version = $this->create([
                'versionable_type' => $model,
                'versionable_id' => $modelId,
                'data' => $data,
                'version' => $this->getNextVersion($model, $modelId),
                'created_by' => auth()->id(),
                'created_at' => now()
            ]);

            $this->invalidateCache('getVersions', $model, $modelId);
            return $version;
        });
    }

    public function getVersions(string $model, int $modelId): Collection
    {
        return $this->executeWithCache(__METHOD__, function() use ($model, $modelId) {
            return $this->model
                ->where('versionable_type', $model)
                ->where('versionable_id', $modelId)
                ->orderBy('version', 'desc')
                ->get();
        }, $model, $modelId);
    }

    public function getVersion(string $model, int $modelId, int $version): ?Version
    {
        return $this->executeWithCache(__METHOD__, function() use ($model, $modelId, $version) {
            return $this->model
                ->where('versionable_type', $model)
                ->where('versionable_id', $modelId)
                ->where('version', $version)
                ->first();
        }, $model, $modelId, $version);
    }

    protected function getNextVersion(string $model, int $modelId): int
    {
        $maxVersion = $this->model
            ->where('versionable_type', $model)
            ->where('versionable_id', $modelId)
            ->max('version');

        return ($maxVersion ?? 0) + 1;
    }

    public function revert(string $model, int $modelId, int $version): ?Version
    {
        return $this->executeTransaction(function() use ($model, $modelId, $version) {
            $targetVersion = $this->getVersion($model, $modelId, $version);
            
            if (!$targetVersion) {
                return null;
            }

            return $this->createVersion($model, $modelId, $targetVersion->data);
        });
    }

    public function pruneVersions(string $model, int $modelId, int $keep = 10): int
    {
        return $this->executeTransaction(function() use ($model, $modelId, $keep) {
            $versions = $this->getVersions($model, $modelId);
            
            if ($versions->count() <= $keep) {
                return 0;
            }

            $versionsToDelete = $versions->slice($keep);
            
            return $this->model
                ->whereIn('id', $versionsToDelete->pluck('id'))
                ->delete();
        });
    }
}
