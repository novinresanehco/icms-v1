<?php

namespace App\Core\Event\Repositories;

use App\Core\Event\Models\Event;
use Illuminate\Support\Collection;
use Carbon\Carbon;

class EventRepository
{
    public function create(array $data): Event
    {
        return Event::create($data);
    }

    public function findOrFail(int $id): Event
    {
        return Event::findOrFail($id);
    }

    public function getScheduledEvents(): Collection
    {
        return Event::where('status', 'pending')
                   ->whereNotNull('scheduled_at')
                   ->where('scheduled_at', '<=', now())
                   ->orderBy('scheduled_at')
                   ->get();
    }

    public function getPendingEvents(): Collection
    {
        return Event::where('status', 'pending')
                   ->whereNull('scheduled_at')
                   ->orderBy('created_at')
                   ->get();
    }

    public function getFailedEvents(): Collection
    {
        return Event::where('status', 'failed')
                   ->orderBy('created_at', 'desc')
                   ->get();
    }

    public function getStats(): array
    {
        return [
            'total_events' => Event::count(),
            'pending_events' => Event::where('status', 'pending')->count(),
            'completed_events' => Event::where('status', 'completed')->count(),
            'failed_events' => Event::where('status', 'failed')->count(),
            'by_type' => Event::selectRaw('type, count(*) as count')
                            ->groupBy('type')
                            ->pluck('count', 'type')
                            ->toArray(),
            'average_processing_time' => Event::whereNotNull('processed_at')
                                           ->selectRaw('AVG(TIMESTAMPDIFF(SECOND, created_at, processed_at)) as avg_time')
                                           ->value('avg_time')
        ];
    }

    public function cleanup(int $days = 30): int
    {
        return Event::where('created_at', '<', Carbon::now()->subDays($days))
                   ->where('status', 'completed')
                   ->delete();
    }
}
