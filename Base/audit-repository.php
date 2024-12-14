<?php

namespace App\Repositories;

use App\Models\Audit;
use App\Repositories\Contracts\AuditRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

class AuditRepository extends BaseRepository implements AuditRepositoryInterface
{
    protected array $searchableFields = ['event', 'auditable_type', 'old_values', 'new_values'];
    protected array $filterableFields = ['user_id', 'auditable_type', 'event'];

    public function log(
        string $event,
        $auditable,
        array $oldValues = [],
        array $newValues = [],
        array $metadata = []
    ): Audit {
        $audit = $this->create([
            'event' => $event,
            'auditable_type' => get_class($auditable),
            'auditable_id' => $auditable->id,
            'user_id' => auth()->id(),
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'metadata' => array_merge($metadata, [
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent()
            ])
        ]);

        Cache::tags(['audits'])->flush();

        return $audit;
    }

    public function getAuditHistory($auditable): Collection
    {
        $cacheKey = 'audits.' . get_class($auditable) . '.' . $auditable->id;

        return Cache::tags(['audits'])->remember($cacheKey, 3600, function() use ($auditable) {
            return $this->model
                ->where('auditable_type', get_class($auditable))
                ->where('auditable_id', $auditable->id)
                ->with('user')
                ->orderByDesc('created_at')
                ->get();
        });
    }

    public function getUserAudits(int $userId): Collection
    {
        $cacheKey = 'audits.user.' . $userId;

        return Cache::tags(['audits'])->remember($cacheKey, 3600, function() use ($userId) {
            return $this->model
                ->where('user_id', $userId)
                ->with(['user', 'auditable'])
                ->orderByDesc('created_at')
                ->get();
        });
    }

    public function getRecentAudits(int $limit = 50): Collection
    {
        $cacheKey = 'audits.recent.' . $limit;

        return Cache::tags(['audits'])->remember($cacheKey, 300, function() use ($limit) {
            return $this->model
                ->with(['user', 'auditable'])
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get();
        });
    }

    public function getAuditsByType(string $type, array $dateRange = []): Collection
    {
        $cacheKey = 'audits.type.' . $type . '.' . md5(serialize($dateRange));

        return Cache::tags(['audits'])->remember($cacheKey, 3600, function() use ($type, $dateRange) {
            $query = $this->model
                ->where('auditable_type', $type)
                ->with(['user', 'auditable']);

            if (!empty($dateRange)) {
                $query->whereBetween('created_at', $dateRange);
            }

            return $query->orderByDesc('created_at')->get();
        });
    }

    public function getAuditsByEvent(string $event, array $dateRange = []): Collection
    {
        $cacheKey = 'audits.event.' . $event . '.' . md5(serialize($dateRange));

        return Cache::tags(['audits'])->remember($cacheKey, 3600, function() use ($event, $dateRange) {
            $query = $this->model
                ->where('event', $event)
                ->with(['user', 'auditable']);

            if (!empty($dateRange)) {
                $query->whereBetween('created_at', $dateRange);
            }

            return $query->orderByDesc('created_at')->get();
        });
    }

    public function getAuditStats(array $dateRange = []): array
    {
        $cacheKey = 'audits.stats.' . md5(serialize($dateRange));

        return Cache::tags(['audits'])->remember($cacheKey, 3600, function() use ($dateRange) {
            $query = $this->model->newQuery();

            if (!empty($dateRange)) {
                $query->whereBetween('created_at', $dateRange);
            }

            return [
                'total_events' => $query->count(),
                'events_by_type' => $query->groupBy('event')
                    ->selectRaw('event, count(*) as count')
                    ->pluck('count', 'event'),
                'audits_by_user' => $query->groupBy('user_id')
                    ->selectRaw('user_id, count(*) as count')
                    ->pluck('count', 'user_id'),
                'audits_by_model' => $query->groupBy('auditable_type')
                    ->selectRaw('auditable_type, count(*) as count')
                    ->pluck('count', 'auditable_type')
            ];
        });
    }

    public function purgeOldAudits(int $days = 90): int
    {
        $count = $this->model
            ->where('created_at', '<', now()->subDays($days))
            ->delete();

        if ($count > 0) {
            Cache::tags(['audits'])->flush();
        }

        return $count;
    }
}
