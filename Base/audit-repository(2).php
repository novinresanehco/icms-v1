<?php

namespace App\Repositories;

use App\Models\Audit;
use App\Core\Repositories\BaseRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class AuditRepository extends BaseRepository
{
    public function __construct(Audit $model)
    {
        $this->model = $model;
        parent::__construct();
    }

    public function logChanges(Model $model, array $changes, ?string $event = null): Audit
    {
        return $this->create([
            'auditable_type' => get_class($model),
            'auditable_id' => $model->getKey(),
            'user_id' => auth()->id(),
            'event' => $event ?? 'update',
            'old_values' => $changes['old'] ?? [],
            'new_values' => $changes['new'] ?? [],
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent()
        ]);
    }

    public function findByModel(Model $model): Collection
    {
        return $this->executeWithCache(__FUNCTION__, [get_class($model), $model->getKey()], function () use ($model) {
            return $this->model->where('auditable_type', get_class($model))
                             ->where('auditable_id', $model->getKey())
                             ->orderBy('created_at', 'desc')
                             ->get();
        });
    }

    public function findByUser(int $userId): Collection
    {
        return $this->executeWithCache(__FUNCTION__, [$userId], function () use ($userId) {
            return $this->model->where('user_id', $userId)
                             ->orderBy('created_at', 'desc')
                             ->get();
        });
    }

    public function findRecentChanges(int $limit = 50): Collection
    {
        return $this->executeWithCache(__FUNCTION__, [$limit], function () use ($limit) {
            return $this->model->with(['user', 'auditable'])
                             ->orderBy('created_at', 'desc')
                             ->limit($limit)
                             ->get();
        });
    }

    public function findByEvent(string $event): Collection
    {
        return $this->executeWithCache(__FUNCTION__, [$event], function () use ($event) {
            return $this->model->where('event', $event)
                             ->orderBy('created_at', 'desc')
                             ->get();
        });
    }
}
