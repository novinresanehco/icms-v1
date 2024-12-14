<?php

namespace App\Core\Webhook\Services;

use App\Core\Webhook\Models\{Webhook, WebhookDelivery};
use Illuminate\Support\Facades\Http;

class WebhookProcessor
{
    public function dispatch(Webhook $webhook, array $payload): bool
    {
        try {
            $response = Http::timeout($webhook->timeout)
                ->withHeaders($this->getHeaders($webhook, $payload))
                ->post($webhook->url, $payload);

            $success = $response->successful();
            $this->recordDelivery($webhook, $payload, $response, $success);

            if ($success) {
                $webhook->resetFailedAttempts();
            } else {
                $webhook->incrementFailedAttempts();
            }

            return $success;
        } catch (\Exception $e) {
            $this->handleError($webhook, $payload, $e);
            return false;
        }
    }

    public function retry(Webhook $webhook): bool
    {
        $lastDelivery = $webhook->deliveries()
                               ->where('status', 'failed')
                               ->latest()
                               ->first();

        if (!$lastDelivery) {
            return false;
        }

        return $this->dispatch($webhook, $lastDelivery->payload);
    }

    public function test(Webhook $webhook): bool
    {
        return $this->dispatch($webhook, [
            'event' => 'test',
            'timestamp' => now()->toIso8601String()
        ]);
    }

    protected function getHeaders(Webhook $webhook, array $payload): array
    {
        return [
            'Content-Type' => 'application/json',
            'User-Agent' => 'Webhook-Client/1.0',
            'X-Webhook-Event' => $webhook->event,
            'X-Webhook-Signature' => $webhook->generateSignature($payload),
            'X-Webhook-Delivery' => uniqid('whd_')
        ];
    }

    protected function recordDelivery(Webhook $webhook, array $payload, $response, bool $success): void
    {
        $webhook->deliveries()->create([
            'payload' => $payload,
            'response' => $response->json() ?? $response->body(),
            'status_code' => $response->status(),
            'status' => $success ? 'success' : 'failed',
            'duration' => $response->handlerStats()['total_time'] ?? null
        ]);
    }

    protected function handleError(Webhook $webhook, array $payload, \Exception $e): void
    {
        $webhook->deliveries()->create([
            'payload' => $payload,
            'response' => $e->getMessage(),
            'status' => 'failed',
            'status_code' => 0
        ]);

        $webhook->incrementFailedAttempts();

        logger()->error('Webhook delivery failed', [
            'webhook_id' => $webhook->id,
            'url' => $webhook->url,
            'error' => $e->getMessage()
        ]);
    }
}
