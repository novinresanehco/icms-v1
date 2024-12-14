<?php

namespace App\Core\Webhook\Services;

use App\Core\Webhook\Exceptions\WebhookValidationException;

class WebhookValidator
{
    public function validate(string $event, string $url, array $options = []): void
    {
        $this->validateEvent($event);
        $this->validateUrl($url);
        $this->validateOptions($options);
    }

    protected function validateEvent(string $event): void
    {
        if (empty($event)) {
            throw new WebhookValidationException('Event name cannot be empty');
        }

        $allowedEvents = config('webhook.events', []);
        if (!empty($allowedEvents) && !in_array($event, $allowedEvents)) {
            throw new WebhookValidationException("Invalid event: {$event}");
        }
    }

    protected function validateUrl(string $url): void
    {
        if (empty($url)) {
            throw new WebhookValidationException('URL cannot be empty');
        }

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new WebhookValidationException('Invalid URL format');
        }

        $this->validateUrlProtocol($url);
    }

    protected function validateUrlProtocol(string $url): void
    {
        $protocol = parse_url($url, PHP_URL_SCHEME);
        if (!in_array($protocol, ['http', 'https'])) {
            throw new WebhookValidationException('Invalid URL protocol');
        }
    }

    protected function validateOptions(array $options): void
    {
        if (isset($options['retry_limit']) && $options['retry_limit'] < 0) {
            throw new WebhookValidationException('Retry limit cannot be negative');
        }

        if (isset($options['timeout']) && $options['timeout'] < 1) {
            throw new WebhookValidationException('Timeout must be at least 1 second');
        }
    }
}
