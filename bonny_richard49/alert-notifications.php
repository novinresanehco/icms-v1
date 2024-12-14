<?php

namespace App\Core\Notification\Analytics\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\SlackMessage;
use Illuminate\Support\HtmlString;
use Carbon\Carbon;

class MetricAnomalyNotification extends Notification
{
    use Queueable;

    public string $metricName;
    public float $currentValue;
    public float $threshold;
    public string $severity;
    public array $context;

    public function __construct(
        string $metricName,
        float $currentValue,
        float $threshold,
        string $severity,
        array $context = []
    ) {
        $this->metricName = $metricName;
        $this->currentValue = $currentValue;
        $this->threshold = $threshold;
        $this->severity = $severity;
        $this->context = $context;
    }

    public function via($notifiable): array
    {
        return $this->determineChannels($notifiable);
    }

    public function toMail($notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject($this->getEmailSubject())
            ->greeting($this->getGreeting())
            ->line($this->getAlertDescription());

        // Add metric details
        $message->line(new HtmlString($this->getMetricDetailsHtml()));

        // Add context information if available
        if (!empty($this->context)) {
            $message->line('Additional Context:')
                   ->line(new HtmlString($this->getContextDetailsHtml()));
        }

        // Add appropriate action button based on severity
        if ($this->severity === 'critical') {
            $message->error('View Alert Details')
                   ->action('View Dashboard', $this->getDashboardUrl());
        } else {
            $message->warning('View Alert Details')
                   ->action('View Dashboard', $this->getDashboardUrl());
        }

        return $message;
    }

    public function toSlack($notifiable): SlackMessage
    {
        $message = (new SlackMessage)
            ->from('Notification Analytics')
            ->warning()
            ->content($this->getAlertDescription());

        if ($this->severity === 'critical') {
            $message->error();
        }

        $message->attachment(function ($attachment) {
            $attachment
                ->title('Metric Details')
                ->fields([
                    'Metric' => $this->metricName,
                    'Current Value' => number_format($this->currentValue, 2),
                    'Threshold' => number_format($this->threshold, 2),
                    'Severity' => ucfirst($this->severity),
                    'Detected At' => Carbon::now()->toDateTimeString()
                ]);

            if (!empty($this->context)) {
                $attachment->fields($this->formatContextForSlack());
            }
        });

        return $message;
    }

    public function toArray($notifiable): array
    {
        return [
            'metric_name' => $this->metricName,
            'current_value' => $this->currentValue,
            'threshold' => $this->threshold,
            'severity' => $this->severity,
            'context' => $this->context,
            'detected_at' => Carbon::now()->toDateTimeString()
        ];
    }

    private function determineChannels($notifiable): array
    {
        $channels = ['database']; // Always store in database

        // Get notifiable preferences
        $preferences = $notifiable->notification_preferences ?? [];

        if (($preferences['email'] ?? true) && $this->shouldSendEmail()) {
            $channels[] = 'mail';
        }

        if (($preferences['slack'] ?? true) && $this->shouldSendSlack()) {
            $channels[] = 'slack';
        }

        return $channels;
    }

    private function shouldSendEmail(): bool
    {
        return $this->severity === 'critical' || 
               ($this->severity === 'warning' && config('notification.analytics.email_warnings', true));
    }

    private function shouldSendSlack(): bool
    {
        return true; // Can implement more complex logic based on requirements
    }

    private function getEmailSubject(): string
    {
        $prefix = $this->severity === 'critical' ? '🚨 CRITICAL' : '⚠️ Warning';
        return "{$prefix}: Anomaly Detected in {$this->metricName}";
    }

    private function getGreeting(): string
    {
        return $this->severity === 'critical' 
            ? 'Critical Alert!'
            : 'Warning Alert';
    }

    private function getAlertDescription(): string
    {
        return "An anomaly has been detected in the {$this->metricName} metric. " .
               "The current value ({$this->currentValue}) has exceeded the defined threshold ({$this->threshold}).";
    }

    private function getMetricDetailsHtml(): string
    {
        return "
            <div style='margin: 20px 0; padding: 15px; background-color: #f8f9fa; border-radius: 4px;'>
                <p><strong>Metric:</strong> {$this->metricName}</p>
                <p><strong>Current Value:</strong> " . number_format($this->currentValue, 2) . "</p>
                <p><strong>Threshold:</strong> " . number_format($this->threshold, 2) . "</p>
                <p><strong>Severity:</strong> " . ucfirst($this->severity) . "</p>
                <p><strong>Detected At:</strong> " . Carbon::now()->toDateTimeString() . "</p>
            </div>
        ";
    }

    private function getContextDetailsHtml(): string
    {
        $html = '<div style="margin: 20px 0; padding: 15px; background-color: #f8f9fa; border-radius: 4px;">';
        
        foreach ($this->context as $key => $value) {
            $formattedKey = ucwords(str_replace('_', ' ', $key));
            $formattedValue = is_array($value) ? json_encode($value) : $value;
            $html .= "<p><strong>{$formattedKey}:</strong> {$formattedValue}</p>";
        }
        
        $html .= '</div>';
        
        return $html;
    }

    private function formatContextForSlack(): array
    {
        $fields = [];
        
        foreach ($this->context as $key => $value) {
            $formattedKey = ucwords(str_replace('_', ' ', $key));
            $formattedValue = is_array($value) ? json_encode($value) : $value;
            $fields[$formattedKey] = $formattedValue;
        }
        
        return $fields;
    }

    private function getDashboardUrl(): string
    {
        return config('notification.analytics.dashboard_url') . 
               "?metric={$this->metricName}&severity={$this->severity}";
    }
}
