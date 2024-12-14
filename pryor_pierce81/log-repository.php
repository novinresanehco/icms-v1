<?php

namespace App\Core\Repository;

use App\Models\Log;
use App\Core\Exceptions\LogRepositoryException;

class LogRepository extends BaseRepository
{
    protected function getModelClass(): string
    {
        return Log::class;
    }

    /**
     * Log system event
     */
    public function logEvent(string $type, array $data, string $level = 'info'): Log
    {
        try {
            return $this->create([
                'type' => $type,
                'level' => $level,
                'data' => $data,
                'user_id' => auth()->id(),
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent()
            ]);
        } catch (\Exception $e) {
            throw new LogRepositoryException(
                "Failed to log event: {$e->getMessage()}"
            );
        }
    }

    /**
     * Get logs by level
     */
    public function getByLevel(string $level, array $options = []): Collection
    {
        $query = $this->model->where('level', $level);

        if (isset($options['from'])) {
            $query->where('created_at', '>=', $options['from']);
        }

        if (isset($options['to'])) {
            $query->where('created_at', '<=', $options['to']);
        }

        if (isset($options['type'])) {
            $query->where('type', $options['type']);
        }

        return $query->latest()->get();
    }

    /**
     * Get user activity logs
     */
    public function getUserActivity(int $userId): Collection
    {
        return $this->model->where('user_id', $userId)
                          ->latest()
                          ->limit(100)
                          ->get();
    }

    /**
     * Clean old logs
     */
    public function cleanOldLogs(int $days = 30): int
    {
        try {
            return $this->model->where('created_at', '<', now()->subDays($days))
                              ->delete();
        } catch (\Exception $e) {
            throw new LogRepositoryException(
                "Failed to clean old logs: {$e->getMessage()}"
            );
        }
    }

    /**
     * Get error logs
     */
    public function getErrorLogs(array $options = []): Collection
    {
        $query = $this->model->where('level', 'error');

        if (isset($options['from'])) {
            $query->where('created_at', '>=', $options['from']);
        }

        if (isset($options['search'])) {
            $query->where(function($q) use ($options) {
                $q->where('data->message', 'like', "%{$options['search']}%")
                  ->orWhere('data->exception', 'like', "%{$options['search']}%");
            });
        }

        return $query->latest()->get();
    }
}
