<?php

namespace App\Core\Queue\Repositories;

use App\Core\Queue\Models\QueueJob;
use Illuminate\Support\Collection;
use Carbon\Carbon;

class QueueRepository
{
    public function create(array $data): QueueJob
    {
        return QueueJob::create($data);
    }

    public function findOrFail(int $id): QueueJob
    {
        return QueueJob::findOrFail($id);
    }

    public function updateStatus(QueueJob $job, string $status): bool
    {
        return $job->update(['status' => $status]);
    }

    public function getWithFilters(array $filters = []): Collection
    {
        $query = QueueJob::query();

        if (!empty($filters['type'])) {
            $query->byType($filters['type']);
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['queue'])) {
            $query->byQueue($filters['queue']);
        }

        if (!empty($filters['priority'])) {
            $query->byPriority($filters['priority']);
        }

        if (!empty($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to']);
        }

        return $query->orderBy('created_at', 'desc')->get();
    }

    public function getNextJob(string $queue): ?QueueJob
    {
        return QueueJob::pending()
            ->byQueue($queue)
            ->orderBy('priority', 'desc')
            ->orderBy('created_at', 'asc')
            ->first();
    }

    public function cleanup(int $olderThanDays): int
    {
        return QueueJob::where('created_at', '<', Carbon::now()->subDays($olderThanDays))
            ->whereIn('status', ['completed', 'cancelled'])
            ->delete();
    }

    public function getStats(): array
    {
        return [
            'total' => QueueJob::count(),
            'pending' => QueueJob::pending()->count(),
            'processing' => QueueJob::processing()->count(),
            'failed' => QueueJob::failed()->count(),
            'by_type' => QueueJob::selectRaw('type, count(*) as count')
                ->groupBy('type')
                ->pluck('count', 'type')
                ->toArray(),
            'by_queue' => QueueJob::selectRaw('queue, count(*) as count')
                ->groupBy('queue')
                ->pluck('count', 'queue')
                ->toArray()
        ];
    }
}
