<?php

namespace App\Core\Webhook\Services;

use App\Core\Webhook\Models\Webhook;
use App\Core\Webhook\Repositories\WebhookRepository;
use Illuminate\Support\Facades\Http;

class WebhookService
{
    public function __construct(
        private WebhookRepository $repository,
        private WebhookValidator $validator,
        private WebhookProcessor $processor
    ) {}

    public function create(string $event, string $url, array $options = []): Webhook
    {
        $this->validator->validate($event, $url, $options);

        return $this->repository->create([
            'event' => $event,
            'url' => $url,
            'secret' => $options['secret'] ?? $this->generateSecret(),
            'is_active' => true,
            'retry_limit' => $options['retry_limit'] ?? 3,
            'timeout' => $options['timeout'] ?? 30
        ]);
    }

    public function trigger(string $event, array $payload): array
    {
        $webhooks = $this->repository->getActiveWebhooks($event);
        $results = [];

        foreach ($webhooks as $webhook) {
            $results[$webhook->id] = $this->processor->dispatch($webhook, $payload);
        }

        return $results;
    }

    public function retry(Webhook $webhook): bool
    {
        if (!$webhook->canRetry()) {
            throw new WebhookException('Maximum retry attempts reached');
        }

        return $this->processor->retry($webhook);
    }

    public function deactivate(Webhook $webhook): bool
    {
        return $this->repository->update($webhook, ['is_active' => false]);
    }

    public function test(Webhook $webhook): bool
    {
        return $this->processor->test($webhook);
    }

    public function getDeliveries(Webhook $webhook): Collection
    {
        return $this->repository->getDeliveries($webhook);
    }

    public function getStats(): array
    {
        return $this->repository->getStats();
    }

    protected function generateSecret(int $length = 32): string
    {
        return bin2hex(random_bytes($length / 2));
    }
}
