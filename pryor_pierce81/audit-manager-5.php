<?php

namespace App\Core\Audit;

use App\Core\Logging\ActivityLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

class AuditManager
{
    protected ActivityLogger $activityLogger;
    protected array $defaultEvents = [
        'created',
        'updated',
        'deleted',
        'restored',
        'synced'
    ];

    public function __construct(ActivityLogger $activityLogger)
    {
        $this->activityLogger = $activityLogger;
    }

    public function recordAudit(string $event, $model, array $properties = []): void
    {
        DB::transaction(function() use ($event, $model, $properties) {
            $this->activityLogger->logActivity($event, $model, $properties);
            
            if ($event === 'created') {
                $this->recordCreation($model);
            } elseif ($event === 'updated') {
                $this->recordUpdate($model);
            } elseif ($event === 'deleted') {
                $this->recordDeletion($model);
            }
        });
    }

    public function getAuditTrail($model): Collection
    {
        return Activity::where('model_type', get_class($model))
            ->where('model_id', $model->getKey())
            ->with('user')
            ->orderBy('created_at', 'desc')
            ->get();
    }

    protected function recordCreation($model): void
    {
        $this->activityLogger->logActivity('model_created', $model, [
            'attributes' => $model->getAttributes()
        ]);
    }

    protected function recordUpdate($model): void
    {
        if ($model->isDirty()) {
            $this->activityLogger->logChanges('model_updated', $model, $model->getOriginal());
        }
    }

    protected function recordDeletion($model): void
    {
        $this->activityLogger->logActivity('model_deleted', $model, [
            'attributes' => $model->getAttributes()
        ]);
    }
}

trait Auditable
{
    protected static function bootAuditable()
    {
        static::created(function($model) {
            app(AuditManager::class)->recordAudit('created', $model);
        });

        static::updated(function($model) {
            app(AuditManager::class)->recordAudit('updated', $model);
        });

        static::deleted(function($model) {
            app(AuditManager::class)->recordAudit('deleted', $model);
        });

        if (method_exists(static::class, 'restored')) {
            static::restored(function($model) {
                app(AuditManager::class)->recordAudit('restored', $model);
            });
        }
    }

    public function audits()
    {
        return app(AuditManager::class)->getAuditTrail($this);
    }
}

class AuditReport
{
    protected $filters = [];
    protected $groupBy = [];
    protected $dateRange;

    public function setFilters(array $filters): self
    {
        $this->filters = $filters;
        return $this;
    }

    public function setGroupBy(array $groupBy): self
    {
        $this->groupBy = $groupBy;
        return $this;
    }

    public function setDateRange($startDate, $endDate): self
    {
        $this->dateRange = [$startDate, $endDate];
        return $this;
    }

    public function generate(): array
    {
        $query = Activity::query();

        foreach ($this->filters as $field => $value) {
            $query->where($field, $value);
        }

        if ($this->dateRange) {
            $query->whereBetween('created_at', $this->dateRange);
        }

        if (!empty($this->groupBy)) {
            $query->groupBy($this->groupBy);
        }

        return [
            'data' => $query->get(),
            'summary' => $this->generateSummary($query),
            'metrics' => $this->calculateMetrics($query)
        ];
    }

    protected function generateSummary($query): array
    {
        return [
            'total_records' => $query->count(),
            'unique_users' => $query->distinct('user_id')->count(),
            'event_types' => $query->distinct('event')->pluck('event')
        ];
    }

    protected function calculateMetrics($query): array
    {
        return [
            'activity_by_day' => $this->getActivityByDay($query),
            'activity_by_type' => $this->getActivityByType($query),
            'activity_by_user' => $this->getActivityByUser($query)
        ];
    }
}
