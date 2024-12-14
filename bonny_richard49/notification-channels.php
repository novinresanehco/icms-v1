<?php

namespace App\Core\Notifications\Channels;

use App\Core\Contracts\NotificationChannelInterface;
use Illuminate\Support\Facades\Log;

abstract class BaseNotificationChannel implements NotificationChannelInterface
{
    protected SecurityManager $security;
    protected array $config;
    protected bool $isEnabled;

    public function __construct(SecurityManager $security, array $config)
    {
        $this->security = $security;
        $this->config = $config;
        $this->isEnabled = $config['enabled'] ?? false;
    }

    abstract protected function deliverNotification(array $recipients, string $message, array $context): bool;
    abstract protected function validateChannelConfig(): bool;
}

class EmailChannel extends BaseNotificationChannel
{
    protected function deliverNotification(array $recipients, string $message, array $context): bool
    {
        try {
            // Validate recipients
            $validatedRecipients = $this->validateEmailRecipients($recipients);
            
            // Prepare email content
            $content = $this->prepareEmailContent($message, $context);
            
            // Send through configured mailer
            $mailer = app()->make('mailer');
            
            foreach ($validatedRecipients as $recipient) {
                $mailer->send(new CriticalAlertEmail(
                    recipient: $recipient,
                    content: $content,
                    context: $context
                ));
            }
            
            return true;
            
        } catch (\Exception $e) {
            Log::error('Email notification failed', [
                'error' => $e->getMessage(),
                'recipients' => $recipients,
                'context' => $context
            ]);
            return false;
        }
    }

    protected function validateChannelConfig(): bool
    {
        return isset($this->config['mail_driver']) && 
               isset($this->config['from_address']) &&
               isset($this->config['from_name']);
    }

    private function validateEmailRecipients(array $recipients): array
    {
        return array_filter($recipients, function($email) {
            return filter_var($email, FILTER_VALIDATE_EMAIL);
        });
    }

    private function prepareEmailContent(string $message, array $context): string 
    {
        $template = $this->config['templates'][$context['type']] ?? null;
        if (!$template) {
            return $message;
        }
        return view($template, [
            'message' => $message,
            'context' => $context
        ])->render();
    }
}

class SMSChannel extends BaseNotificationChannel
{
    protected function deliverNotification(array $recipients, string $message, array $context): bool
    {
        try {
            // Validate phone numbers
            $validatedRecipients = $this->validatePhoneNumbers($recipients);
            
            // Prepare message content
            $content = $this->prepareSMSContent($message, $context);
            
            // Send through SMS provider
            $provider = app()->make($this->config['sms_provider']);
            
            foreach ($validatedRecipients as $recipient) {
                $provider->sendMessage(
                    to: $recipient,
                    message: $content,
                    priority: $context['severity'] === 'critical' ? 'high' : 'normal'
                );
            }
            
            return true;
            
        } catch (\Exception $e) {
            Log::error('SMS notification failed', [
                'error' => $e->getMessage(),
                'recipients' => $recipients,
                'context' => $context
            ]);
            return false;
        }
    }

    protected function validateChannelConfig(): bool
    {
        return isset($this->config['sms_provider']) && 
               isset($this->config['api_key']) &&
               isset($this->config['sender_id']);
    }

    private function validatePhoneNumbers(array $numbers): array
    {
        return array_filter($numbers, function($number) {
            return preg_match('/^\+[1-9]\d{1,14}$/', $number);
        });
    }

    private function prepareSMSContent(string $message, array $context): string
    {
        // Ensure message fits SMS length limits
        $prefix = $context['severity'] === 'critical' ? 'CRITICAL: ' : '';
        return substr($prefix . $message, 0, 160);
    }
}

class SlackChannel extends BaseNotificationChannel
{
    protected function deliverNotification(array $recipients, string $message, array $context): bool
    {
        try {
            // Validate channels
            $validatedChannels = $this->validateSlackChannels($recipients);
            
            // Prepare message payload
            $payload = $this->prepareSlackPayload($message, $context);
            
            // Send through Slack client
            $client = app()->make('slack');
            
            foreach ($validatedChannels as $channel) {
                $client->to($channel)->send($payload);
            }
            
            return true;
            
        } catch (\Exception $e) {
            Log::error('Slack notification failed', [
                'error' => $e->getMessage(),
                'channels' => $recipients,
                'context' => $context
            ]);
            return false;
        }
    }

    protected function validateChannelConfig(): bool
    {
        return isset($this->config['webhook_url']) && 
               isset($this->config['default_channel']);
    }

    private function validateSlackChannels(array $channels): array
    {
        return array_filter($channels, function($channel) {
            return preg_match('/^[#@][a-zA-Z0-9_-]+$/', $channel);
        });
    }

    private function prepareSlackPayload(string $message, array $context): array
    {
        $color = $this->getAlertColor($context['severity']);
        
        return [
            'attachments' => [
                [
                    'color' => $color,
                    'title' => $context['type'],
                    'text' => $message,
                    'fields' => $this->getSlackFields($context),
                    'footer' => 'System Monitoring Alert',
                    'ts' => time()
                ]
            ]
        ];
    }

    private function getAlertColor(string $severity): string
    {
        return match($severity) {
            'critical' => '#FF0000',
            'warning' => '#FFA500',
            'info' => '#0000FF',
            default => '#808080'
        };
    }

    private function getSlackFields(array $context): array
    {
        $fields = [];
        
        if (isset($context['metrics'])) {
            foreach ($context['metrics'] as $key => $value) {
                $fields[] = [
                    'title' => ucfirst($key),
                    'value' => $value,
                    'short' => true
                ];
            }
        }
        
        return $fields;
    }
}
