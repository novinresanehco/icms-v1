<?php

namespace App\Core\Notifications\Handlers;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Notifications\NotificationHandlerInterface;
use App\Core\Notifications\NotificationResult;
use App\Core\Exception\NotificationException;
use Psr\Log\LoggerInterface;

class SmsNotificationHandler implements NotificationHandlerInterface
{
    private SecurityManagerInterface $security;
    private LoggerInterface $logger;
    private SmsProviderInterface $provider;
    private array $config;

    public function __construct(
        SecurityManagerInterface $security,
        LoggerInterface $logger,
        SmsProviderInterface $provider,
        array $config = []
    ) {
        $this->security = $security;
        $this->logger = $logger;
        $this->provider = $provider;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    public function handle(array $notification): NotificationResult
    {
        $messageId = uniqid('sms_', true);

        try {
            // Validate SMS-specific requirements
            $this->validateSmsNotification($notification);

            // Security check for SMS sending
            $this->security->validateOperation('sms:send', $notification['recipient']);

            // Normalize phone number
            $recipient = $this->normalizePhoneNumber($notification['recipient']);

            // Rate limiting check
            $this->checkRateLimit($recipient);

            // Send with retry mechanism
            $sent = $this->sendWithRetry(
                $recipient,
                $notification['content'],
                $this->config['retry_attempts']
            );

            if (!$sent) {
                throw new NotificationException('Failed to send SMS after retries');
            }

            // Log success
            $this->logSuccess($messageId, $notification);

            return new NotificationResult(true, $messageId);

        } catch (\Exception $e) {
            $this->handleFailure($messageId, $notification, $e);
            throw new NotificationException('SMS send failed', 0, $e);
        }
    }

    public function isSupported(): bool
    {
        return $this->provider !== null && $this->validateConfiguration();
    }

    public function getName(): string
    {
        return 'sms';
    }

    private function validateSmsNotification(array $notification): void
    {
        if (empty($notification['recipient'])) {
            throw new NotificationException('SMS recipient is required');
        }

        if (empty($notification['content'])) {
            throw new NotificationException('SMS content is required');
        }

        // Check content length
        if (mb_strlen($notification['content']) > $this->config['max_length']) {
            throw new NotificationException('SMS content exceeds maximum length');
        }

        // Check for banned content
        foreach ($this->config['banned_patterns'] as $pattern) {
            if (preg_match($pattern, $notification['content'])) {
                throw new NotificationException('SMS content contains prohibited pattern');
            }
        }
    }

    private function normalizePhoneNumber(string $number): string
    {
        // Remove all non-numeric characters
        $normalized = preg_replace('/[^0-9]/', '', $number);

        // Validate format
        if (!preg_match($this->config['phone_pattern'], $normalized)) {
            throw new NotificationException('Invalid phone number format');
        }

        return $normalized;
    }

    private function checkRateLimit(string $recipient): void
    {
        $key = "sms_limit:{$recipient}";
        $count = cache()->get($key, 0);

        if ($count >= $this->config['hourly_limit']) {
            throw new NotificationException('SMS rate limit exceeded for recipient');
        }

        // Increment counter with 1-hour expiry
        cache()->put($key, $count + 1, 3600);
    }

    private function sendWithRetry(
        string $recipient,
        string $content,
        int $attempts
    ): bool {
        $attempt = 1;
        $success = false;

        while ($attempt <= $attempts && !$success) {
            try {
                $success = $this->provider->send([
                    'to' => $recipient,
                    'message' => $content,
                    'options' => $this->config['provider_options']
                ]);
            } catch (\Exception $e) {
                $this->logger->warning('SMS send attempt failed', [
                    'attempt' => $attempt,
                    'error' => $e->getMessage()
                ]);

                if ($attempt === $attempts) {
                    throw $e;
                }

                // Exponential backoff
                sleep(pow(2, $attempt - 1));
            }

            $attempt++;
        }

        return $success;
    }

    private function validateConfiguration(): bool
    {
        return !empty($this->config['provider_options']['api_key']) &&
               !empty($this->config['provider_options']['sender_id']);
    }

    private function logSuccess(string $messageId, array $notification): void
    {
        $this->logger->info('SMS sent successfully', [
            'message_id' => $messageId,
            'recipient' => $notification['recipient']
        ]);
    }

    private function handleFailure(
        string $messageId,
        array $notification,
        \Exception $e
    ): void {
        $this->logger->error('SMS send failed', [
            'message_id' => $messageId,
            'recipient' => $notification['recipient'],
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    private function getDefaultConfig(): array
    {
        return [
            'max_length' => 160,
            'hourly_limit' => 10,
            'retry_attempts' => 3,
            'phone_pattern' => '/^[1-9][0-9]{7,15}$/',
            'banned_patterns' => [
                '/\b(?:viagra|casino|lottery)\b/i'
            ],
            'provider_options' => [
                'api_key' => null,
                'sender_id' => null,
                'timeout' => 30
            ]
        ];
    }
}
