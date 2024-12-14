<?php

namespace App\Repositories;

use App\Models\Activity;
use App\Repositories\Contracts\ActivityRepositoryInterface;
use Illuminate\Support\Collection;

class ActivityRepository extends BaseRepository implements ActivityRepositoryInterface
{
    protected array $searchableFields = ['description', 'properties'];
    protected array $filterableFields = ['causer_type', 'causer_id', 'subject_type', 'subject_id', 'event'];
    protected array $relationships = ['causer', 'subject'];

    public function __construct(Activity $model)
    {
        parent::__construct($model);
    }

    public function getByUser(int $userId): Collection
    {
        return Cache::remember(
            $this->getCacheKey("user.{$userId}"),
            $this->cacheTTL,
            fn() => $this->model->with($this->relationships)
                ->where('causer_type', User::class)
                ->where('causer_id', $userId)
                ->orderBy('created_at', 'desc')
                ->get()
        );
    }

    public function getBySubject(string $subjectType, int $subjectId): Collection
    {
        return Cache::remember(
            $this->getCacheKey("subject.{$subjectType}.{$subjectId}"),
            $this->cacheTTL,
            fn() => $this->model->with($this->relationships)
                ->where('subject_type', $subjectType)
                ->where('subject_id', $subjectId)
                ->orderBy('created_at', 'desc')
                ->get()
        );
    }

    public function log(
        string $event,
        $subject,
        $causer = null,
        array $properties = []
    ): Activity {
        $activity = $this->create([
            'event' => $event,
            'subject_type' => get_class($subject),
            'subject_id' => $subject->id,
            'causer_type' => $causer ? get_class($causer) : null,
            'causer_id' => $causer ? $causer->id : null,
            'properties' => $properties
        ]);

        $this->clearModelCache();
        return $activity;
    }
}
