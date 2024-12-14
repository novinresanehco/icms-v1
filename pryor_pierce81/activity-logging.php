<?php

namespace App\Core\Logging;

use App\Models\Activity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class ActivityLogger
{
    protected array $excludedAttributes = [
        'password',
        'remember_token',
        'api_token'
    ];

    public function logActivity(string $event, mixed $model = null, array $properties = []): Activity
    {
        try {
            return DB::transaction(function() use ($event, $model, $properties) {
                return Activity::create([
                    'event' => $event,
                    'model_type' => $model ? get_class($model) : null,
                    'model_id' => $model?->getKey(),
                    'user_id' => Auth::id(),
                    'properties' => array_merge(
                        $this->getModelProperties($model),
                        $properties
                    ),
                    'ip_address' => request()->ip(),
                    'user_agent' => request()->userAgent()
                ]);
            });
        } catch (\Exception $e) {
            report($e);
            return null;
        }
    }

    public function logChanges(string $event, mixed $model, array $original): Activity
    {
        $changes = [];
        $current = $model->toArray();

        foreach ($current as $attribute => $value) {
            if (in_array($attribute, $this->excludedAttributes)) {
                continue;
            }

            if (!array_key_exists($attribute, $original)) {
                $changes[$attribute] = [
                    'old' => null,
                    'new' => $value
                ];
            } elseif ($original[$attribute] !== $value) {
                $changes[$attribute] = [
                    'old' => $original[$attribute],
                    'new' => $value
                ];
            }
        }

        return $this->logActivity($event, $model, ['changes' => $changes]);
    }

    protected function getModelProperties($model): array
    {
        if (!$model) {
            return [];
        }

        $properties = $model->toArray();

        return array_diff_key(
            $properties,
            array_flip($this->excludedAttributes)
        );
    }
}

class ActivityArchiver
{
    protected int $archiveAfterDays = 90;
    protected int $batchSize = 1000;

    public function archiveOldActivities(): int
    {
        $cutoffDate = now()->subDays($this->archiveAfterDays);
        $count = 0;

        try {
            Activity::where('created_at', '<', $cutoffDate)
                ->chunkById($this->batchSize, function($activities) use (&$count) {
                    foreach ($activities as $activity) {
                        DB::transaction(function() use ($activity) {
                            $this->archiveActivity($activity);
                            $activity->delete();
                        });
                    }
                    $count += $activities->count();
                });

            return $count;
        } catch (\Exception $e) {
            report($e);
            return 0;
        }
    }

    protected function archiveActivity(Activity $activity): void
    {
        DB::table('activity_archive')->insert($activity->toArray());
    }
}

class ActivityQuery
{
    protected $builder;

    public function __construct()
    {
        $this->builder = Activity::query();
    }

    public function forUser(int $userId): self
    {
        $this->builder->where('user_id', $userId);
        return $this;
    }

    public function forModel(string $modelType, int $modelId): self
    {
        $this->builder->where('model_type', $modelType)
            ->where('model_id', $modelId);
        return $this;
    }

    public function ofType(string $event): self
    {
        $this->builder->where('event', $event);
        return $this;
    }

    public function inDateRange($startDate, $endDate): self
    {
        $this->builder->whereBetween('created_at', [$startDate, $endDate]);
        return $this;
    }

    public function get()
    {
        return $this->builder->get();
    }

    public function paginate(int $perPage = 15)
    {
        return $this->builder->paginate($perPage);
    }
}

class Activity extends Model
{
    protected $fillable = [
        'event',
        'model_type',
        'model_id',
        'user_id',
        'properties',
        'ip_address',
        'user_agent'
    ];

    protected $casts = [
        'properties' => 'array'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function causer()
    {
        return $this->morphTo('model');
    }
}
