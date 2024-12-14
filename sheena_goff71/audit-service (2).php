<?php

namespace App\Core\Audit\Services;

use App\Core\Audit\Models\Audit;
use App\Core\Audit\Repositories\AuditRepository;
use Illuminate\Support\Facades\Auth;

class AuditService
{
    public function __construct(
        private AuditRepository $repository,
        private AuditValidator $validator
    ) {}

    public function log(
        string $action,
        string $entityType,
        int $entityId,
        array $data = []
    ): Audit {
        $this->validator->validate($action, $entityType, $data);

        return $this->repository->create([
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'user_id' => Auth::id(),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'data' => $data
        ]);
    }

    public function logChanges(
        string $entityType,
        int $entityId,
        array $oldData,
        array $newData
    ): Audit {
        $changes = $this->detectChanges($oldData, $newData);

        return $this->log('update', $entityType, $entityId, [
            'changes' => $changes,
            'old' => $oldData,
            'new' => $newData
        ]);
    }

    public function getAuditTrail(string $entityType, int $entityId): Collection
    {
        return $this->repository->getForEntity($entityType, $entityId);
    }

    public function getUserActivity(int $userId): Collection
    {
        return $this->repository->getByUser($userId);
    }

    public function search(array $filters = []): Collection
    {
        return $this->repository->getWithFilters($filters);
    }

    public function getStats(array $filters = []): array
    {
        return $this->repository->getStats($filters);
    }

    public function export(array $filters = []): string
    {
        $audits = $this->search($filters);
        return $this->formatForExport($audits);
    }

    protected function detectChanges(array $oldData, array $newData): array
    {
        $changes = [];

        foreach ($newData as $key => $value) {
            if (!array_key_exists($key, $oldData) || $oldData[$key] !== $value) {
                $changes[$key] = [
                    'old' => $oldData[$key] ?? null,
                    'new' => $value
                ];
            }
        }

        return $changes;
    }

    protected function formatForExport(Collection $audits): string
    {
        return $audits->map(function ($audit) {
            return [
                'id' => $audit->id,
                'date' => $audit->created_at->toDateTimeString(),
                'user' => $audit->user ? $audit->user->name : 'System',
                'action' => $audit->action,
                'entity_type' => $audit->entity_type,
                'entity_id' => $audit->entity_id,
                'ip_address' => $audit->ip_address,
                'data' => json_encode($audit->data)
            ];
        })->toJson(JSON_PRETTY_PRINT);
    }
}
