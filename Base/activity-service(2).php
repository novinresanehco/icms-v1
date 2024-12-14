<?php

namespace App\Core\Services;

use App\Core\Repositories\{
    ActivityRepository,
    ActivityTypeRepository,
    AuditTrailRepository,
    ActivityBatchRepository
};
use App\Core\Events\{ActivityLogged, BatchCompleted};
use App\Core\Exceptions\ActivityException;
use Illuminate\Database\Eloquent\{Model, Collection};
use Illuminate\Support\Facades\{DB, Event};

class ActivityService extends BaseService
{
    protected ActivityTypeRepository $typeRepository;
    protected AuditTrailRepository $auditRepository;
    protected ActivityBatchRepository $batchRepository;

    public function __construct(
        ActivityRepository $repository,
        ActivityTypeRepository $typeRepository,
        AuditTrailRepository $auditRepository,
        ActivityBatchRepository $batchRepository
    ) {
        parent::__construct($repository);
        $this->typeRepository = $typeRepository;
        $this->auditRepository = $auditRepository;
        $this->batchRepository = $batchRepository;
    }

    public function log(
        string $description,
        ?Model $subject = null,
        ?Model $causer = null,
        array $properties = []
    ): Model {
        try {
            DB::beginTransaction();

            $activity = $this->repository->log(
                $description,
                $subject,
                $causer,
                $properties
            );

            Event::dispatch(new ActivityLogged($activity));

            DB::commit();

            return $activity;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new ActivityException("Failed to log activity: {$e->getMessage()}", 0, $e);
        }
    }

    public function registerType(string $type, array $config = []): Model
    {
        try {
            return $this->typeRepository->registerType($type, $config);
        } catch (\Exception $e) {
            throw new ActivityException("Failed to register activity type: {$e->getMessage()}", 0, $e);
        }
    }

    public function recordChanges(
        Model $model,
        array $oldValues,
        array $newValues,
        ?Model $causer = null
    ): Model {
        try {
            return $this->auditRepository->recordChanges(
                $model,
                $oldValues,
                $newValues,
                $causer
            );
        } catch (\Exception $e) {
            throw new ActivityException("Failed to record changes: {$e->getMessage()}", 0, $e);
        }
    }

    public function startBatch(string $name, array $metadata = []): Model
    {
        try {
            return $this->batchRepository->startBatch($name, $metadata);
        } catch (\Exception $e) {
            throw new ActivityException("Failed to start batch: {$e->getMessage()}", 0, $e);
        }
    }

    public function completeBatch(Model $batch): bool
    {
        try {
            $completed = $this->batchRepository->completeBatch($batch);

            if ($completed) {
                Event::dispatch(new BatchCompleted($batch));
            }

            return $completed;
        } catch (\Exception $e) {
            throw new ActivityException("Failed to complete batch: {$e->getMessage()}", 0, $e);
        }
    }

    public function failBatch(Model $batch, string $error): bool
    {
        try {
            return $this->batchRepository->failBatch($batch, $error);
        } catch (\Exception $e) {
            throw new ActivityException("Failed to fail batch: {$e->getMessage()}", 0, $e);
        }
    }

    public function getActivityForSubject(Model $subject): Collection
    {
        try {
            return $this->repository->forSubject($subject);
        } catch (\Exception $e) {
            throw new ActivityException("Failed to get activity: {$e->getMessage()}", 0, $e);
        }
    }

    public function getChangeHistory(Model $model): Collection
    {
        try {
            return $this->auditRepository->getChanges($model);
        } catch (\Exception $e) {
            throw new ActivityException("Failed to get change history: {$e->getMessage()}", 0, $e);
        }
    }

    public function getRunningBatches(): Collection
    {
        try {
            return $this->batchRepository->getRunningBatches();
        } catch (\Exception $e) {
            throw new ActivityException("Failed to get running batches: {$e->getMessage()}", 0, $e);
        }
    }
}
