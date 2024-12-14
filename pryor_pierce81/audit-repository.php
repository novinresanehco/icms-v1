<?php

namespace App\Core\Repository;

use App\Models\Audit;
use App\Core\Events\AuditEvents;
use App\Core\Exceptions\AuditRepositoryException;

class AuditRepository extends BaseRepository
{
    protected function getModelClass(): string
    {
        return Audit::class;
    }

    /**
     * Record audit entry
     */
    public function record(string $action, string $entityType, int $entityId, array $data): Audit
    {
        try {
            $audit = $this->create([
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'user_id' => auth()->id(),
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'data' => $data,
                'created_at' => now()
            ]);

            event(new AuditEvents\AuditRecorded($audit));
            return $audit;
        } catch (\Exception $e) {
            throw new AuditRepositoryException(
                "Failed to record audit: {$e->getMessage()}"
            );
        }
    }

    /**
     * Get entity audit trail
     */
    public function getEntityAudit(string $entityType, int $entityId): Collection
    {
        return Cache::tags($this->getCacheTags())->remember(
            $this->getCacheKey("entity.{$entityType}.{$entityId}"),
            $this->cacheTime,
            fn() => $this->model->where('entity_type', $entityType)
                               ->where('entity_id', $entityId)
                               ->with('user')
                               ->latest()
                               ->get()
        );
    }

    /**
     * Get audit history by user
     */
    public function getUserAudit(int $userId, array $options = []): Collection
    {
        $query = $this->model->where('user_id', $userId);

        if (isset($options['action'])) {
            $query->where('action', $options['action']);
        }

        if (isset($options['entity_type'])) {
            $query->where('entity_type', $options['entity_type']);
        }

        if (isset($options['from'])) {
            $query->where('created_at', '>=', $options['from']);
        }

        if (isset($options['to'])) {
            $query->where('created_at', '<=', $options['to']);
        }

        return $query->latest()->get();
    }

    /**
     * Get recent activity
     */
    public function getRecentActivity(int $limit = 50): Collection
    {
        return Cache::tags($this->getCacheTags())->remember(
            $this->getCacheKey('recent', $limit),
            300, // 5 minutes cache
            fn() => $this->model->with(['user', 'entity'])
                               ->latest()
                               ->limit($limit)
                               ->get()
        );
    }
}
