<?php

namespace App\Core\Repositories;

use App\Core\Repositories\Contracts\AuditRepositoryInterface;
use App\Models\Audit;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;

class AuditRepository extends BaseRepository implements AuditRepositoryInterface
{
    public function __construct(Audit $model)
    {
        parent::__construct($model);
    }

    public function logActivity(string $action, array $data = []): bool
    {
        return $this->create([
            'user_id' => Auth::id(),
            'action' => $action,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'data' => $data,
            'created_at' => now()
        ]) instanceof Audit;
    }

    public function getUserActivity(int $userId, int $limit = 50): Collection
    {
        return $this->model
            ->where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    public function getRecentActivity(int $limit = 50): Collection
    {
        return $this->model
            ->with('user')
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    public function searchActivity(array $criteria): Collection
    {
        $query = $this->model->newQuery();

        if (isset($criteria['user_id'])) {
            $query->where('user_id', $criteria['user_id']);
        }

        if (isset($criteria['action'])) {
            $query->where('action', 'LIKE', "%{$criteria['action']}%");
        }

        if (isset($criteria['ip_address'])) {
            $query->where('ip_address', $criteria['ip_address']);
        }

        if (isset($criteria['date_from'])) {
            $query->where('created_at', '>=', $criteria['date_from']);
        }

        if (isset($criteria['date_to'])) {
            $query->where('created_at', '<=', $criteria['date_to']);
        }

        return $query
            ->with('user')
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function getActionStats(): Collection
    {
        return $this->model
            ->select('action', \DB::raw('COUNT(*) as count'))
            ->groupBy('action')
            ->orderByDesc('count')
            ->get();
    }
}
