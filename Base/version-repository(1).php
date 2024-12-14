<?php

namespace App\Core\Repositories;

use App\Core\Models\Version;
use App\Core\Events\{VersionCreated, VersionRestored};
use App\Core\Exceptions\VersionException;
use Illuminate\Database\Eloquent\{Model, Collection, Builder};
use Illuminate\Support\Facades\{DB, Event};

class VersionRepository extends Repository
{
    protected array $with = ['creator'];
    protected int $maxVersions = 50;

    public function createVersion(Model $model, array $attributes = []): Model
    {
        return DB::transaction(function() use ($model, $attributes) {
            $currentVersion = $this->getCurrentVersion($model);
            $nextVersion = ($currentVersion?->version ?? 0) + 1;

            $version = $this->create([
                'versionable_type' => get_class($model),
                'versionable_id' => $model->id,
                'version' => $nextVersion,
                'data' => $this->serializeModel($model),
                'metadata' => $attributes['metadata'] ?? [],
                'created_by' => auth()->id(),
                'parent_version' => $currentVersion?->id
            ]);

            $this->pruneOldVersions($model);

            return $version;
        });
    }

    public function getVersions(Model $model): Collection
    {
        return $this->query()
            ->where('versionable_type', get_class($model))
            ->where('versionable_id', $model->id)
            ->orderByDesc('version')
            ->get();
    }

    public function getVersion(Model $model, int $version): ?Model
    {
        return $this->query()
            ->where('versionable_type', get_class($model))
            ->where('versionable_id', $model->id)
            ->where('version', $version)
            ->first();
    }

    public function restore(Model $version): bool
    {
        return DB::transaction(function() use ($version) {
            $model = $version->versionable;
            
            if (!$model) {
                throw new VersionException('Versionable model not found');
            }

            $model->fill($this->unserializeData($version->data));
            
            if ($model->save()) {
                Event::dispatch(new VersionRestored($version));
                return true;
            }

            return false;
        });
    }

    protected function getCurrentVersion(Model $model): ?Model
    {
        return $this->query()
            ->where('versionable_type', get_class($model))
            ->where('versionable_id', $model->id)
            ->orderByDesc('version')
            ->first();
    }

    protected function pruneOldVersions(Model $model): void
    {
        $versions = $this->getVersions($model);

        if ($versions->count() > $this->maxVersions) {
            $versionsToDelete = $versions->slice($this->maxVersions);
            
            foreach ($versionsToDelete as $version) {
                $this->delete($version);
            }
        }
    }

    protected function serializeModel(Model $model): array
    {
        return array_diff_key(
            $model->toArray(),
            array_flip(['id', 'created_at', 'updated_at'])
        );
    }

    protected function unserializeData(array $data): array
    {
        return array_diff_key(
            $data,
            array_flip(['id', 'created_at', 'updated_at'])
        );
    }
}

class VersionDiffRepository extends Repository
{
    public function createDiff(Model $version1, Model $version2): Model
    {
        return $this->create([
            'version_from' => $version1->id,
            'version_to' => $version2->id,
            'differences' => $this->calculateDiff(
                $version1->data,
                $version2->data
            )
        ]);
    }

    public function getDiff(Model $version1, Model $version2): array
    {
        $diff = $this->query()
            ->where('version_from', $version1->id)
            ->where('version_to', $version2->id)
            ->first();

        if (!$diff) {
            $diff = $this->createDiff($version1, $version2);
        }

        return $diff->differences;
    }

    protected function calculateDiff(array $data1, array $data2): array
    {
        $diff = [];

        foreach ($data2 as $key => $value) {
            if (!isset($data1[$key])) {
                $diff[$key] = [
                    'type' => 'added',
                    'value' => $value
                ];
            } elseif ($data1[$key] !== $value) {
                $diff[$key] = [
                    'type' => 'modified',
                    'old' => $data1[$key],
                    'new' => $value
                ];
            }
        }

        foreach ($data1 as $key => $value) {
            if (!isset($data2[$key])) {
                $diff[$key] = [
                    'type' => 'removed',
                    'value' => $value
                ];
            }
        }

        return $diff;
    }
}

class VersionMetadataRepository extends Repository
{
    public function trackMetadata(Model $version, array $metadata): Model
    {
        return $this->create([
            'version_id' => $version->id,
            'metadata' => $metadata,
            'created_by' => auth()->id()
        ]);
    }

    public function getMetadata(Model $version): Collection
    {
        return $this->query()
            ->where('version_id', $version->id)
            ->orderByDesc('created_at')
            ->get();
    }

    public function getByKey(Model $version, string $key): Collection
    {
        return $this->query()
            ->where('version_id', $version->id)
            ->whereJsonContains('metadata->' . $key, true)
            ->orderByDesc('created_at')
            ->get();
    }
}

class VersionRestorePointRepository extends Repository
{
    public function createRestorePoint(Model $model, string $label): Model
    {
        return DB::transaction(function() use ($model, $label) {
            $version = app(VersionRepository::class)->createVersion($model);

            return $this->create([
                'version_id' => $version->id,
                'label' => $label,
                'created_by' => auth()->id()
            ]);
        });
    }

    public function getRestorePoints(Model $model): Collection
    {
        return $this->query()
            ->whereHas('version', function(Builder $query) use ($model) {
                $query->where('versionable_type', get_class($model))
                    ->where('versionable_id', $model->id);
            })
            ->with('version')
            ->orderByDesc('created_at')
            ->get();
    }
}
