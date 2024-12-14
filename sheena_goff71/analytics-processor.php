<?php

namespace App\Core\Analytics\Services;

use App\Core\Analytics\Models\Event;
use Illuminate\Support\Facades\Queue;
use League\Csv\Writer;

class AnalyticsProcessor
{
    public function process(Event $event): void
    {
        Queue::push(new ProcessAnalyticsEvent($event));
    }

    public function processAsync(Event $event): void
    {
        $this->enrichEvent($event);
        $this->aggregateMetrics($event);
        $this->triggerHooks($event);
    }

    public function exportEvents(array $filters = []): string
    {
        $events = Event::with('user')
            ->when(!empty($filters), function ($query) use ($filters) {
                return $query->where($filters);
            })
            ->orderBy('created_at', 'desc')
            ->get();

        $csv = Writer::createFromString();

        $csv->insertOne([
            'Event ID',
            'Event Name',
            'User ID',
            'Session ID',
            'Properties',
            'IP Address',
            'User Agent',
            'Created At'
        ]);

        foreach ($events as $event) {
            $csv->insertOne([
                $event->id,
                $event->name,
                $event->user_id,
                $event->session_id,
                json_encode($event->properties),
                $event->ip_address,
                $event->user_agent,
                $event->created_at->toDateTimeString()
            ]);
        }

        return $csv->toString();
    }

    protected function enrichEvent(Event $event): void
    {
        // Add device info
        $event->properties['device'] = $this->getDeviceInfo($event->user_agent);
        
        // Add location info
        if ($location = $this->getLocationInfo($event->ip_address)) {
            $event->properties['location'] = $location;
        }

        $event->save();
    }

    protected function aggregateMetrics(Event $event): void
    {
        // Aggregate event counts
        cache()->increment("analytics:event:{$event->name}:count");
        cache()->increment("analytics:user:{$event->user_id}:events");

        // Aggregate by time period
        $date = $event->created_at->format('Y-m-d');
        cache()->increment("analytics:daily:{$date}:{$event->name}");
    }

    protected function triggerHooks(Event $event): void
    {
        $hooks = config('analytics.hooks', []);
        
        foreach ($hooks as $hook) {
            if ($hook::shouldProcess($event)) {
                Queue::push(new ProcessAnalyticsHook($hook, $event));
            }
        }
    }

    protected function getDeviceInfo(string $userAgent): array
    {
        // Implement device detection logic
        return [];
    }

    protected function getLocationInfo(string $ipAddress): ?array
    {
        // Implement IP geolocation logic
        return null;
    }
}
