<?php

namespace App\Core\Audit\Formatters;

class NotificationFormatter
{
    private array $formatters;

    public function __construct(array $formatters)
    {
        $this->formatters = $formatters;
    }

    public function format(AbstractNotification $notification): FormattedNotification
    {
        $type = $notification->getType();
        
        if (!isset($this->formatters[$type])) {
            throw new \InvalidArgumentException("No formatter found for type: {$type}");
        }

        $formatter = $this->formatters[$type];
        return $formatter->format($notification);
    }
}

class FormattedNotification
{
    private string $subject;
    private string $body;
    private array $attachments;
    private array $metadata;

    public function __construct(string $subject, string $body, array $attachments = [], array $metadata = [])
    {
        $this->subject = $subject;
        $this->body = $body;
        $this->attachments = $attachments;
        $this->metadata = $metadata;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getAttachments(): array
    {
        return $this->attachments;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }
}

interface NotificationFormatterInterface
{
    public function format(AbstractNotification $notification): FormattedNotification;
}

class AnalysisCompletedFormatter implements NotificationFormatterInterface
{
    public function format(AbstractNotification $notification): FormattedNotification
    {
        $data = $notification->getData();
        
        return new FormattedNotification(
            'Analysis Completed Successfully',
            $this->formatBody($data),
            $this->formatAttachments($data),
            $notification->getMetadata()
        );
    }

    private function formatBody(array $data): string
    {
        return sprintf(
            "Analysis completed successfully\n\n" .
            "Duration: %s\n" .
            "Processed Items: %d\n" .
            "Findings: %d\n",
            $data['duration'],
            $data['processed_items'],
            count($data['findings'])
        );
    }

    private function formatAttachments(array $data): array
    {
        return [
            [
                'title' => 'Analysis Summary',
                'fields' => [
                    'Duration' => $data['duration'],
                    'Items Processed' => $data['processed_items'],
                    'Findings' => count($data['findings'])
                ]
            ],
            [
                'title' => 'Detailed Results',
                'fields' => $data['findings']
            ]
        ];
    }
}

class AnomalyDetectedFormatter implements NotificationFormatterInterface
{
    public function format(AbstractNotification $notification): FormattedNotification
    {
        $data = $notification->getData();
        
        return new FormattedNotification(
            'Anomaly Detected',
            $this->formatBody($data),
            $this->formatAttachments($data),
            $notification->getMetadata()
        );
    }

    private function formatBody(array $data): string
    {
        return sprintf(
            "Anomaly detected in analysis\n\n" .
            "Type: %s\n" .
            "Severity: %s\n" .
            "Confidence: %.2f%%\n" .
            "Description: %s\n",
            $data['type'],
            $data['severity'],
            $data['confidence'] * 100,
            $data['description']
        );
    }

    private function formatAttachments(array $data): array
    {
        return [
            [
                'title' => 'Anomaly Details',
                'color' => $this->getSeverityColor($data['severity']),
                'fields' => [
                    'Type' => $data['type'],
                    'Severity' => $data['severity'],
                    'Confidence' => sprintf('%.2f%%', $data['confidence'] * 100),
                    'Description' => $data['description']
                ]
            ],
            [
                'title' => 'Context',
                'fields' => $data['context']
            ]
        ];
    }

    private function getSeverityColor(string $severity): string
    {
        return match($severity) {
            'critical' => '#ff0000',
            'high' => '#ff9900',
            'medium' => '#ffff00',
            'low' => '#00ff00',
            default => '#cccccc'
        };
    }
}
