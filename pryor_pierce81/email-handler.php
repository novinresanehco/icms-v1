<?php

namespace App\Core\Notifications\Handlers;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Notifications\NotificationHandlerInterface;
use App\Core\Notifications\NotificationResult;
use App\Core\Exception\NotificationException;
use Psr\Log\LoggerInterface;

class EmailNotificationHandler implements NotificationHandlerInterface 
{
    private SecurityManagerInterface $security;
    private LoggerInterface $logger;
    private array $config;
    private \Swift_Mailer $mailer;

    public function __construct(
        SecurityManagerInterface $security,
        LoggerInterface $logger,
        \Swift_Mailer $mailer,
        array $config = []
    ) {
        $this->security = $security;
        $this->logger = $logger;
        $this->mailer = $mailer;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    public function handle(array $notification): NotificationResult
    {
        $messageId = uniqid('email_', true);

        try {
            // Validate email-specific requirements
            $this->validateEmailNotification($notification);

            // Security check for email sending
            $this->security->validateOperation('email:send', $notification['recipient']);

            // Create email message
            $message = $this->createEmailMessage($notification);

            // Send with retry mechanism
            $sent = $this->sendWithRetry($message, $this->config['retry_attempts']);

            if (!$sent) {
                throw new NotificationException('Failed to send email after retries');
            }

            // Log success
            $this->logSuccess($messageId, $notification);

            return new NotificationResult(true, $messageId);

        } catch (\Exception $e) {
            $this->handleFailure($messageId, $notification, $e);
            throw new NotificationException('Email send failed', 0, $e);
        }
    }

    public function isSupported(): bool
    {
        return $this->mailer !== null && $this->validateConfiguration();
    }

    public function getName(): string
    {
        return 'email';
    }

    private function validateEmailNotification(array $notification): void
    {
        if (!filter_var($notification['recipient'], FILTER_VALIDATE_EMAIL)) {
            throw new NotificationException('Invalid email recipient');
        }

        if (empty($notification['content']['subject'])) {
            throw new NotificationException('Email subject is required');
        }

        if (empty($notification['content']['body'])) {
            throw new NotificationException('Email body is required');
        }

        // Check content size limits
        if (strlen($notification['content']['subject']) > $this->config['max_subject_length']) {
            throw new NotificationException('Email subject exceeds maximum length');
        }

        if (strlen($notification['content']['body']) > $this->config['max_body_length']) {
            throw new NotificationException('Email body exceeds maximum length');
        }
    }

    private function createEmailMessage(array $notification): \Swift_Message
    {
        $message = new \Swift_Message();
        
        $message->setSubject($this->sanitizeContent($notification['content']['subject']))
                ->setFrom($this->config['from_address'])
                ->setTo($notification['recipient'])
                ->setBody($this->sanitizeContent($notification['content']['body']));

        if (!empty($notification['content']['html_body'])) {
            $message->addPart(
                $this->sanitizeContent($notification['content']['html_body']),
                'text/html'
            );
        }

        return $message;
    }

    private function sendWithRetry(\Swift_Message $message, int $attempts): bool
    {
        $attempt = 1;
        $success = false;

        while ($attempt <= $attempts && !$success) {
            try {
                $success = $this->mailer->send($message) > 0;
            } catch (\Exception $e) {
                $this->logger->warning('Email send attempt failed', [
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

    private function sanitizeContent(string $content): string
    {
        // Remove potentially dangerous content
        return strip_tags($content, $this->config['allowed_tags']);
    }

    private function validateConfiguration(): bool
    {
        return !empty($this->config['from_address']) &&
               filter_var($this->config['from_address'], FILTER_VALIDATE_EMAIL);
    }

    private function logSuccess(string $messageId, array $notification): void
    {
        $this->logger->info('Email sent successfully', [
            'message_id' => $messageId,
            'recipient' => $notification['recipient'],
            'subject' => $notification['content']['subject']
        ]);
    }

    private function handleFailure(
        string $messageId, 
        array $notification, 
        \Exception $e
    ): void {
        $this->logger->error('Email send failed', [
            'message_id' => $messageId,
            'recipient' => $notification['recipient'],
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    private function getDefaultConfig(): array
    {
        return [
            'from_address' => null,
            'retry_attempts' => 3,
            'max_subject_length' => 255,
            'max_body_length' => 1048576, // 1MB
            'allowed_tags' => '<p><br><a><strong><em>'
        ];
    }
}
