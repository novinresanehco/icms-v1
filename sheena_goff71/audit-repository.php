<?php

namespace App\Core\Audit\Repositories;

use App\Core\Audit\Models\Audit;
use Illuminate\Support\Collection;
use Carbon\Carbon;

class AuditRepository
{
    public function create(array $data): Audit
    {
        return Audit::create($data);
    }

    public function getForEntity(string $entityType, int $entityId): Collection
    {
        return Audit::byEntity($entityType, $entityId)
                    ->with('user')
                    ->orderBy('created_at', 'desc')
                    ->get();
    }

    public function getByUser(int $userId): Collection
    {
        return Audit::byUser($userId)
                    ->orderBy('created_at', 'desc')
                    ->get();
    }

    public function getWithFilters(array $filters = []): Collection
    {
        $query = Audit::query();

        if (!empty($filters['action'])) {
            $query->byAction($filters['action']);
        }

        if (!empty($filters['entity_type'])) {
            $query->byEntity($filters['entity_type'], $filters['entity_id'] ?? null);
        }

        if (!empty($filters['user_id'])) {
            $query->byUser($filters['user_id']);
        }

        if (!empty($filters['date_from']) && !empty($filters['date_to'])) {
            $query->inDateRange($filters['date_from'], $filters['date_to']);
        }

        if (!empty($filters['ip_address'])) {
            $query->where('ip_address', $filters['ip_address']);
        }

        return $query->with('user')
                    ->orderBy('created_at', 'desc')
                    ->get();
    }

    public function getStats(array $filters = []): array
    {
        $query = Audit::query();

        if (!empty($filters)) {
            $this->applyFilters($query, $filters);
        }

        return [
            'total_entries' => $query->count(),
            'by_action' => $query->selectRaw('action, count(*) as count')
                                ->groupBy('action')
                                ->pluck('count', 'action')
                                ->toArray(),
            'by_entity' => $query->selectRaw('entity_type, count(*) as count')
                                ->groupBy('entity_type')
                                ->pluck('count', 'entity_type')
                                ->toArray(),
            'by_user' => $query->selectRaw('user_id, count(*) as count')
                              ->groupBy('user_id')
                              ->pluck('count', 'user_id')
                              ->toArray()
        ];
    }

    private function applyFilters($query, array $filters): void
    {
        foreach ($filters as $key => $value) {
            switch ($key) {
                case 'action':
                    $query->byAction($value);
                    break;
                case 'entity_type':
                    $query->byEntity($value, $filters['entity_id'] ?? null);
                    break;
                case 'user_id':
                    $query->byUser($value);
                    break;
                case 'date_from':
                case 'date_to':
                    if (isset($filters['date_from']) && isset($filters['date_to'])) {
                        $query->inDateRange($filters['date_from'], $filters['date_to']);
                    }
                    break;
                case 'ip_address':
                    $query->where('ip_address', $value);
                    break;
            }
        }
    }
}
