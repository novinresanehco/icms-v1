<?php

namespace App\Core\Notification\Analytics\Channels;

class SmsAlertChannel
{
    // ... (previous code)

    public function getRetryDelay(int $attempts): int
    {
        // Exponential backoff with jitter: 2^n + random(0-1) seconds
        return pow(2, $attempts) + rand(0, 100) / 100;
    }

    public function getMaxAttempts(): int
    {
        return self::MAX_ATTEMPTS;
    }

    private function getRateLimitKey(string $key): string
    {
        return self::CACHE_PREFIX . $key;
    }

    private function getSeverityPrefix(string $severity): string
    {
        return match($severity) {
            'critical' => '!!! ',
            'warning' => '!! ',
            'info' => '! ',
            default => ''
        };
    }

    private function truncateMessage(string $message): string
    {
        if (strlen($message) <= self::MAX_MESSAGE_LENGTH) {
            return $message;
        }

        return substr($message, 0, self::MAX_MESSAGE_LENGTH - 3) . '...';
    }

    private function formatData(array $data): string
    {
        $formatted = [];
        foreach ($data as $key => $value) {
            $label = ucwords(str_replace('_', ' ', $key));
            $formatted[] = "{$label}: " . (is_array($value) ? json_encode($value) : $value);
        }

        return implode("\n", $formatted);
    }

    private function getRecipients(array $options): array
    {
        $recipients = $options['recipients'] ?? $this->config['default_recipients'] ?? [];

        if (empty($recipients)) {
            throw new Exception('No recipients specified for SMS alert');
        }

        // Filter and validate phone numbers
        return array_filter($recipients, function($number) {
            return $this->validatePhoneNumber($number);
        });
    }

    private function validatePhoneNumber(string $number): bool
    {
        // Remove any non-numeric characters except +
        $number = preg_replace('/[^\d+]/', '', $number);
        
        // Basic validation - can be enhanced based on specific requirements
        return strlen($number) >= 10 && strlen($number) <= 15;
    }

    private function sendSms(string $recipient, string $message): array
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->config['api_key'],
            'Content-Type' => 'application/json'
        ])->post($this->config['api_url'] . '/send', [
            'to' => $recipient,
            'message' => $message,
            'sender_id' => $this->config['sender_id'],
            'unicode' => $this->config['unicode_support']
        ]);

        if (!$response->successful()) {
            throw new Exception(
                "SMS API error: " . ($response->json('message') ?? $response->body())
            );
        }

        return [
            'success' => true,
            'message_id' => $response->json('message_id'),
            'status' => $response->json('status'),
            'timestamp' => now()->toIso8601String()
        ];
    }
}
