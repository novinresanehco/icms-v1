<?php

namespace App\Core\Webhook\Repositories;

use App\Core\Webhook\Models\{Webhook, WebhookDelivery};
use Illuminate\Support\Collection;

class WebhookRepository
{
    public function create(array $data): Webhook
    {
        return Webhook::create($data);
    }

    public function update(Webhook $webhook, array $data): bool
    {
        return $webhook->update($data);
    }

    public function getActiveWebhooks(string $event): Collection
    {
        return Webhook::where('event', $event)
                     ->where('is_active', true)
                     ->get();
    }

    public function getDeliveries(Webhook $webhook): Collection
    {
        return $webhook->deliveries()
                      ->orderBy('created_at', 'desc')
                      ->get();
    }

    public function recordDelivery(Webhook $webhook, array $data): WebhookDelivery
    {
        return WebhookDelivery::create(array_merge(
            $data,
            ['webhook_id' => $webhook->id]
        ));
    }

    public function getStats(): array
    {
        return [
            'total_webhooks' => Webhook::count(),
            'active_webhooks' => Webhook::where('is_active', true)->count(),
            'total_deliveries' => WebhookDelivery::count(),
            'failed_deliveries' => WebhookDelivery::where('status', 'failed')->count(),
            'by_event' => Webhook::selectRaw('event, count(*) as count')
                               ->groupBy('event')
                               ->pluck('count', 'event')
                               ->toArray()
        ];
    }
}
