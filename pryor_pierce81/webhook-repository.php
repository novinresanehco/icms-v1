<?php

namespace App\Core\Repository;

use App\Models\Webhook;
use App\Core\Events\WebhookEvents;
use App\Core\Exceptions\WebhookRepositoryException;

class WebhookRepository extends BaseRepository
{
    protected function getModelClass(): string
    {
        return Webhook::class;
    }

    /**
     * Create webhook
     */
    public function createWebhook(array $data): Webhook
    {
        try {
            DB::beginTransaction();

            // Generate secret if not provided
            if (!isset($data['secret'])) {
                $data['secret'] = Str::random(32);
            }

            $webhook = $this->create([
                'name' => $data['name'],
                'url' => $data['url'],
                'events' => $data['events'],
                'secret' => $data['secret'],
                'status' => 'active',
                'headers' => $data['headers'] ?? [],
                'created_by' => auth()->id()
            ]);

            DB::commit();
            event(new WebhookEvents\WebhookCreated($webhook));

            return $webhook;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new WebhookRepositoryException(
                "Failed to create webhook: {$e->getMessage()}"
            );
        }
    }

    /**
     * Get webhooks for event
     */
    public function getWebhooksForEvent(string $event): Collection
    {
        return Cache::tags($this->getCacheTags())->remember(
            $this->getCacheKey("event.{$event}"),
            $this->cacheTime,
            fn() => $this->model
                ->where('status', 'active')
                ->where(function($query) use ($event) {
                    $query->whereJsonContains('events', $event)
                          ->orWhereJsonContains('events', '*');
                })
                ->get()
        );
    }

    /**
     * Log webhook delivery
     */
    public function logDelivery(Webhook $webhook, string $event, array $data): void
    {
        try {
            DB::table('webhook_deliveries')->insert([
                'webhook_id' => $webhook->id,
                'event' => $event,
                'payload' => json_encode($data),
                'status' => 'pending',
                'created_at' => now()
            ]);
        } catch (\Exception $e) {
            \Log::error("Failed to log webhook delivery: {$e->getMessage()}", [
                'webhook_id' => $webhook->id,
                'event' => $event
            ]);
        }
    }

    /**
     * Update delivery status
     */
    public function updateDeliveryStatus(int $deliveryId, string $status, ?string $response = null): void
    {
        try {
            DB::table('webhook_deliveries')
                ->where('id', $deliveryId)
                ->update([
                    'status' => $status,
                    'response' => $response,
                    'delivered_at' => now()
                ]);
        } catch (\Exception $e) {
            \Log::error("Failed to update webhook delivery status: {$e->getMessage()}", [
                'delivery_id' => $deliveryId,
                'status' => $status
            ]);
        }
    }

    /**
     * Get webhook delivery history
     */
    public function getDeliveryHistory(int $webhookId, array $options = []): Collection
    {
        $query = DB::table('webhook_deliveries')
            ->where('webhook_id', $webhookId);

        if (isset($options['status'])) {
            $query->where('status', $options['status']);
        }

        if (isset($options['event'])) {
            $query->where('event', $options['event']);
        }

        if (isset($options['from'])) {
            $query->where('created_at', '>=', $options['from']);
        }

        return $query->orderByDesc('created_at')->get();
    }

    /**
     * Test webhook
     */
    public function testWebhook(int $webhookId): bool
    {
        try {
            $webhook = $this->find($webhookId);
            if (!$webhook) {
                throw new WebhookRepositoryException("Webhook not found with ID: {$webhookId}");
            }

            // Send test event
            $this->dispatchWebhook($webhook, 'test', [
                'message' => 'This is a test event',
                'timestamp' => now()->timestamp
            ]);

            return true;

        } catch (\Exception $e) {
            throw new WebhookRepositoryException(
                "Failed to test webhook: {$e->getMessage()}"
            );
        }
    }

    /**
     * Dispatch webhook
     */
    protected function dispatchWebhook(Webhook $webhook, string $event, array $data): void
    {
        // Implementation of actual webhook dispatch
        // This would typically be handled by a job/queue
        dispatch(new DispatchWebhookJob($webhook, $event, $data));
    }
}
