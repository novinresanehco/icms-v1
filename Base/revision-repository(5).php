<?php

namespace App\Repositories;

use App\Models\Revision;
use App\Core\Repositories\AbstractRepository;
use Illuminate\Support\Collection;

class RevisionRepository extends AbstractRepository
{
    protected array $with = ['user'];

    public function log(string $model, int $modelId, string $action, array $changes): Revision
    {
        return $this->create([
            'model_type' => $model,
            'model_id' => $modelId,
            'action' => $action,
            'changes' => $changes,
            'user_id' => auth()->id(),
            'ip_address' => request()->ip()
        ]);
    }

    public function getModelHistory(string $model, int $modelId): Collection
    {
        return $this->executeQuery(function() use ($model, $modelId) {
            return $this->model->where('model_type', $model)
                ->where('model_id', $modelId)
                ->with($this->with)
                ->latest()
                ->get();
        });
    }

    public function getUserActivity(int $userId): Collection
    {
        return $this->executeQuery(function() use ($userId) {
            return $this->model->where('user_id', $userId)
                ->with(['model'])
                ->latest()
                ->get();
        });
    }

    public function getRecentActivity(int $limit = 50): Collection
    {
        return $this->executeQuery(function() use ($limit) {
            return $this->model->with($this->with)
                ->latest()
                ->limit($limit)
                ->get();
        });
    }

    public function pruneOldRevisions(int $days = 90): int
    {
        return $this->model->where('created_at', '<', now()->subDays($days))->delete();
    }
}
