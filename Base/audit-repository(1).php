<?php

namespace App\Core\Repositories;

use App\Models\Audit;
use App\Core\Services\Cache\CacheService;
use Illuminate\Support\Collection;

class AuditRepository extends AdvancedRepository
{
    protected $model = Audit::class;
    protected $cache;

    public function __construct(CacheService $cache)
    {
        parent::__construct();
        $this->cache = $cache;
    }

    public function log(
        string $action,
        string $description,
        $subject = null,
        array $properties = []
    ): Audit {
        return $this->executeTransaction(function() use ($action, $description, $subject, $properties) {
            return $this->create([
                'user_id' => auth()->id(),
                'action' => $action,
                'description' => $description,
                'subject_type' => $subject ? get_class($subject) : null,
                'subject_id' => $subject ? $subject->id : null,
                'properties' => $properties,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'created_at' => now()
            ]);
        });
    }

    public function getForUser(int $userId, array $filters = []): Collection
    {
        return $this->executeQuery(function() use ($userId, $filters) {
            $query = $this->model
                ->where('user_id', $userId)
                ->with(['subject', 'user']);

            if (isset($filters['action'])) {
                $query->where('action', $filters['action']);
            }

            if (isset($filters['date_from'])) {
                $query->where('created_at', '>=', $filters['date_from']);
            }

            if (isset($filters['date_to'])) {
                $query->where('created_at', '<=', $filters['date_to']);
            }

            return $query->orderBy('created_at', 'desc')->get();
        });
    }

    public function getForSubject($subject): Collection
    {
        return $this->executeQuery(function() use ($subject) {
            return $this->model
                ->where('subject_type', get_class($subject))
                ->where('subject_id', $subject->id)
                ->with('user')
                ->orderBy('created_at', 'desc')
                ->get();
        });
    }

    public function pruneOldRecords(int $days = 90): int
    {
        return $this->executeTransaction(function() use ($days) {
            return $this->model
                ->where('created_at', '<=', now()->subDays($days))
                ->delete();
        });
    }
}
