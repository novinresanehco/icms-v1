<?php

namespace App\Core\Repositories;

use App\Models\Log;
use Illuminate\Support\Collection;

class LogRepository extends AdvancedRepository
{
    protected $model = Log::class;
    
    public function log(string $channel, string $level, string $message, array $context = []): void
    {
        $this->executeTransaction(function() use ($channel, $level, $message, $context) {
            $this->create([
                'channel' => $channel,
                'level' => $level,
                'message' => $message,
                'context' => $context,
                'created_at' => now()
            ]);
        });
    }

    public function getByChannel(string $channel, int $limit = 100): Collection
    {
        return $this->executeQuery(function() use ($channel, $limit) {
            return $this->model
                ->where('channel', $channel)
                ->latest()
                ->limit($limit)
                ->get();
        });
    }

    public function getByLevel(string $level, int $limit = 100): Collection
    {
        return $this->executeQuery(function() use ($level, $limit) {
            return $this->model
                ->where('level', $level)
                ->latest()
                ->limit($limit)
                ->get();
        });
    }

    public function search(array $criteria, array $dateRange = null): Collection
    {
        return $this->executeQuery(function() use ($criteria, $dateRange) {
            $query = $this->model->newQuery();

            foreach ($criteria as $field => $value) {
                $query->where($field, $value);
            }

            if ($dateRange) {
                $query->whereBetween('created_at', [
                    $dateRange['start'],
                    $dateRange['end']
                ]);
            }

            return $query->latest()->get();
        });
    }

    public function purgeOldLogs(int $days = 30): int
    {
        return $this->executeTransaction(function() use ($days) {
            return $this->model
                ->where('created_at', '<=', now()->subDays($days))
                ->delete();
        });
    }
}
