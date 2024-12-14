<?php

namespace App\Core\Analytics\Repositories;

use App\Core\Analytics\Models\{Event, UserTrait};
use Illuminate\Support\Collection;
use Carbon\CarbonPeriod;

class AnalyticsRepository
{
    public function create(array $data): Event
    {
        return Event::create($data);
    }

    public function updateUserTraits(int $userId, array $traits): void
    {
        foreach ($traits as $key => $value) {
            UserTrait::updateOrCreate(
                ['user_id' => $userId, 'key' => $key],
                ['value' => $value]
            );
        }
    }

    public function getEvents(array $filters = []): Collection
    {
        $query = Event::query();

        if (!empty($filters['event'])) {
            $query->ofType($filters['event']);
        }

        if (!empty($filters['user_id'])) {
            $query->byUser($filters['user_id']);
        }

        if (!empty($filters['session_id'])) {
            $query->bySession($filters['session_id']);
        }

        if (!empty($filters['start']) && !empty($filters['end'])) {
            $query->inTimeRange($filters['start'], $filters['end']);
        }

        if (!empty($filters['properties'])) {
            foreach ($filters['properties'] as $key => $value) {
                $query->where("properties->{$key}", $value);
            }
        }

        return $query->get();
    }

    public function getMetrics(string $event, string $metric, array $filters = []): array
    {
        $events = $this->getEvents(array_merge($filters, ['event' => $event]));

        $start = $filters['start'] ?? now()->subMonth();
        $end = $filters['end'] ?? now();
        $interval = $filters['interval'] ?? 'day';

        $period = CarbonPeriod::create($start, "1 {$interval}", $end);
        $metrics = [];

        foreach ($period as $date) {
            $dateStr = $date->format('Y-m-d');
            $metrics[$dateStr] = $this->calculateMetric(
                $events->filter(fn($event) => $event->created_at->format('Y-m-d') === $dateStr),
                $metric
            );
        }

        return $metrics;
    }

    public function getFunnels(array $steps, array $filters = []): array
    {
        $result = [];
        $users = collect();

        foreach ($steps as $i => $step) {
            $events = $this->getEvents(array_merge($filters, ['event' => $step]));
            
            if ($i === 0) {
                $users = $events->pluck('user_id')->unique();
            } else {
                $users = $users->intersect($events->pluck('user_id')->unique());
            }

            $result[] = [
                'step' => $step,
                'count' => $users->count(),
                'conversion' => $i === 0 ? 100 : 
                    round($users->count() / $result[0]['count'] * 100, 2)
            ];
        }

        return $result;
    }

    public function getRetention(array $criteria, array $filters = []): array
    {
        $initialEvent = $criteria['initial_event'];
        $returnEvent = $criteria['return_event'];
        $periods = $criteria['periods'] ?? 7;

        $initialUsers = $this->getEvents(array_merge($filters, [
            'event' => $initialEvent
        ]))->pluck('user_id')->unique();

        $retention = [];
        for ($i = 0; $i < $periods; $i++) {
            $start = now()->subDays($i + 1)->startOfDay();
            $end = now()->subDays($i)->endOfDay();

            $returnUsers = $this->getEvents(array_merge($filters, [
                'event' => $returnEvent,
                'start' => $start,
                'end' => $end
            ]))->pluck('user_id')->unique();

            $retention[] = [
                'day' => $i + 1,
                'count' => $returnUsers->intersect($initialUsers)->count(),
                'percentage' => round(
                    $returnUsers->intersect($initialUsers)->count() / $initialUsers->count() * 100,
                    2
                )
            ];
        }

        return $retention;
    }

    public function getStats(): array
    {
        return [
            'total_events' => Event::count(),
            'unique_users' => Event::distinct('user_id')->count(),
            'by_event_type' => Event::selectRaw('name, count(*) as count')
                                  ->groupBy('name')
                                  ->pluck('count', 'name')
                                  ->toArray(),
            'recent_events' => Event::where('created_at', '>=', now()->subDay())
                                  ->count()
        ];
    }

    public function cleanup(int $days): int
    {
        return Event::where('created_at', '<', now()->subDays($days))->delete();
    }

    protected function calculateMetric(Collection $events, string $metric): mixed
    {
        return match($metric) {
            'count' => $events->count(),
            'unique_users' => $events->pluck('user_id')->unique()->count(),
            'average_value' => $events->avg('properties.value'),
            default => throw new \InvalidArgumentException("Unknown metric: {$metric}")
        };
    }
}
