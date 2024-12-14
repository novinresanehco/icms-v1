<?php

namespace App\Core\Services;

use App\Core\Repositories\{VersionRepository, VersionDiffRepository, VersionMetadataRepository, VersionRestorePointRepository};
use App\Core\Events\{VersionCreated, VersionRestored, RestorePointCreated};
use App\Core\Exceptions\VersionServiceException;
use Illuminate\Database\Eloquent\{Model, Collection};
use Illuminate\Support\Facades\{DB, Event};

class VersionControlService extends BaseService
{
    protected VersionDiffRepository $diffRepository;
    protected VersionMetadataRepository $metadataRepository;
    protected VersionRestorePointRepository $restorePointRepository;
    
    public function __construct(
        VersionRepository $repository,
        VersionDiffRepository $diffRepository,
        VersionMetadataRepository $metadataRepository,
        VersionRestorePointRepository $restorePointRepository
    ) {
        parent::__construct($repository);
        $this->diffRepository = $diffRepository;
        $this->metadataRepository = $metadataRepository;
        $this->restorePointRepository = $restorePointRepository;
    }

    public function createVersion(Model $model, array $attributes = []): Model
    {
        try {
            DB::beginTransaction();

            $version = $this->repository->createVersion($model, $attributes);
            
            if (isset($attributes['metadata'])) {
                $this->metadataRepository->trackMetadata($version, $attributes['metadata']);
            }
            
            Event::dispatch(new VersionCreated($version));

            DB::commit();

            return $version;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new VersionServiceException("Failed to create version: {$e->getMessage()}", 0, $e);
        }
    }

    public function createRestorePoint(Model $model, string $label): Model
    {
        try {
            DB::beginTransaction();

            $restorePoint = $this->restorePointRepository->createRestorePoint($model, $label);
            
            Event::dispatch(new RestorePointCreated($restorePoint));

            DB::commit();

            return $restorePoint;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new VersionServiceException("Failed to create restore point: {$e->getMessage()}", 0, $e);
        }
    }

    public function restore(Model $version): bool
    {
        try {
            DB::beginTransaction();

            $restored = $this->repository->restore($version);
            
            if ($restored) {
                $this->createVersion($version->versionable, [
                    'metadata' => ['restored_from' => $version->id]
                ]);
            }

            DB::commit();

            return $restored;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new VersionServiceException("Failed to restore version: {$e->getMessage()}", 0, $e);
        }
    }

    public function getDiff(Model $version1, Model $version2): array
    {
        return $this->diffRepository->getDiff($version1, $version2);
    }

    public function getVersions(Model $model): Collection
    {
        return $this->repository->getVersions($model);
    }

    public function getRestorePoints(Model $model): Collection
    {
        return $this->restorePointRepository->getRestorePoints($model);
    }

    public function getMetadata(Model $version): Collection
    {
        return $this->metadataRepository->getMetadata($version);
    }
}
