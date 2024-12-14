<?php

namespace App\Core\Repositories;

use App\Core\Contracts\Repositories\ActivityRepositoryInterface;
use App\Core\Models\Activity;
use App\Core\Exceptions\ActivityRepositoryException;
use Illuminate\Database\Eloquent\{Model, Collection, Builder};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class ActivityRepository implements ActivityRepositoryInterface
{
    protected Activity $model;

    public function __construct(Activity $model)
    {
        $this->model = $model;
    }

    /**
     * Log a new activity
     *
     * @param string $description
     * @param Model|null $subject
     * @param Model|null $causer
     * @param array $properties
     * @return Model
     * @throws ActivityRepositoryException
     */
    public function log(
        string $description,
        ?Model $subject = null,
        ?Model $causer = null,
        array $properties = []
    ): Model {
        try {
            return $this->model->create([
                'description' => $description,
                'subject_type' => $subject ? get_class($subject) : null,
                'subject_id' => $subject?->getKey(),
                'causer_type' => $causer ? get_class($causer) : null,
                'causer_id' => $causer?->getKey(),
                'properties' => $properties,
                'created_at' => Carbon::now()
            ]);
        } catch (\Exception $e) {
            throw new ActivityRepositoryException(
                "Failed to log activity: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    /**
     * Get activities for a specific subject
     *
     * @param Model $subject
     * @return Collection
     * @throws ActivityRepositoryException
     */
    public function forSubject(Model $subject): Collection
    {
        try {
            return $this->model->newQuery()
                ->where('subject_type', get_class($subject))
                ->where('subject_id', $subject->getKey())
                ->orderBy('created_at', 'desc')
                ->get();
        } catch (\Exception $e) {
            throw new ActivityRepositoryException(
                "Failed to retrieve activities: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    /**
     * Get activities by specific type
     *
     * @param string $type
     * @return Collection
     * @throws ActivityRepositoryException
     */
    public function getByType(string $type): Collection
    {
        try {
            return $this->model->newQuery()
                ->where('type', $type)
                ->orderBy('created_at', 'desc')
                ->get();
        } catch (\Exception $e) {
            throw new ActivityRepositoryException(
                "Failed to retrieve activities by type: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    /**
     * Get activities within a date range
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return Collection
     * @throws ActivityRepositoryException
     */
    public function getInDateRange(Carbon $startDate, Carbon $endDate): Collection
    {
        try {
            return $this->model->newQuery()
                ->whereBetween('created_at', [$startDate, $endDate])
                ->orderBy('created_at', 'desc')
                ->get();
        } catch (\Exception $e) {
            throw new ActivityRepositoryException(
                "Failed to retrieve activities in date range: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    /**
     * Clean up old activities
     *
     * @param int $daysToKeep
     * @return bool
     * @throws ActivityRepositoryException
     */
    public function cleanup(int $daysToKeep): bool
    {
        try {
            $cutoffDate = Carbon::now()->subDays($daysToKeep);
            
            return DB::transaction(function () use ($cutoffDate) {
                return $this->model->newQuery()
                    ->where('created_at', '<', $cutoffDate)
                    ->delete() > 0;
            });
        } catch (\Exception $e) {
            throw new ActivityRepositoryException(
                "Failed to cleanup activities: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    /**
     * Get activities by causer
     *
     * @param Model $causer
     * @return Collection
     * @throws ActivityRepositoryException
     */
    public function getByCauser(Model $causer): Collection
    {
        try {
            return $this->model->newQuery()
                ->where('causer_type', get_class($causer))
                ->where('causer_id', $causer->getKey())
                ->orderBy('created_at', 'desc')
                ->get();
        } catch (\Exception $e) {
            throw new ActivityRepositoryException(
                "Failed to retrieve activities by causer: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    /**
     * Get a paginated list of all activities
     *
     * @param int $perPage
     * @return Collection
     * @throws ActivityRepositoryException
     */
    public function getPaginated(int $perPage = 15): Collection
    {
        try {
            return $this->model->newQuery()
                ->orderBy('created_at', 'desc')
                ->paginate($perPage);
        } catch (\Exception $e) {
            throw new ActivityRepositoryException(
                "Failed to retrieve paginated activities: {$e->getMessage()}",
                0,
                $e
            );
        }
    }
}
