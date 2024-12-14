<?php

namespace App\Core\Monitoring\Alerts;

class AlertDispatcher {
    private array $channels;
    private AlertFormatter $formatter;
    private AlertLogger $logger;

    public function __construct(
        array $channels,
        AlertFormatter $formatter,
        AlertLogger $logger
    ) {
        $this->channels = $channels;
        $this->formatter = $formatter;
        $this->logger = $logger;
    }

    public function dispatch(PerformanceAlert $alert): void 
    {
        $formattedAlert = $this->formatter->format($alert);
        
        $this->logger->logAlert($alert);

        foreach ($this->channels as $channel) {
            if ($channel->shouldHandle($alert)) {
                $channel->send($formattedAlert);
            }
        }
    }
}

abstract class AlertChannel {
    protected array $config;
    protected AlertFilter $filter;

    public function __construct(array $config, AlertFilter $filter) 
    {
        $this->config = $config;
        $this->filter = $filter;
    }

    abstract public function send(array $alert): void;

    public function shouldHandle(PerformanceAlert $alert): bool 
    {
        return $this->filter->shouldProcess($alert);
    }
}

class EmailAlertChannel extends AlertChannel {
    private EmailClient $emailClient;

    public function __construct(
        array $config,
        AlertFilter $filter,
        EmailClient $emailClient
    ) {
        parent::__construct($config, $filter);
        $this->emailClient = $emailClient;
    }

    public function send(array $alert): void 
    {
        $this->emailClient->send(
            $this->config['recipients'],
            $alert['subject'],
            $alert['body']
        );
    }
}

class SlackAlertChannel extends AlertChannel {
    private SlackClient $slackClient;

    public function __construct(
        array $config,
        AlertFilter $filter,
        SlackClient $slackClient
    ) {
        parent::__construct($config, $filter);
        $this->slackClient = $slackClient;
    }

    public function send(array $alert): void 
    {
        $this->slackClient->sendMessage(
            $this->config['webhook_url'],
            $alert['message'],
            $alert['attachments'] ?? []
        );
    }
}

class AlertFormatter {
    public function format(PerformanceAlert $alert): array 
    {
        $metric = $alert->getMetric();
        $threshold = $alert->getThreshold();

        return [
            'subject' => "Performance Alert: {$metric->getKey()} threshold exceeded",
            'message' => $this->formatMessage($alert),
            'body' => $this->formatBody($alert),
            'attachments' => $this->formatAttachments($alert),
            'metadata' => [
                'metric_key' => $metric->getKey(),
                'value' => $metric->getValue(),
                'threshold' => $threshold->getValue(),
                'severity' => $threshold->getSeverity(),
                'timestamp' => $alert->getTimestamp()
            ]
        ];
    }

    private function formatMessage(PerformanceAlert $alert): string 
    {
        $metric = $alert->getMetric();
        $threshold = $alert->getThreshold();

        return sprintf(
            "Metric '%s' value %.2f exceeded threshold %.2f (%s)",
            $metric->getKey(),
            $metric->getValue(),
            $threshold->getValue(),
            $threshold->getSeverity()
        );
    }

    private function formatBody(PerformanceAlert $alert): string 
    {
        $metric = $alert->getMetric();
        
        return sprintf(
            "Performance Alert Details:\n\n" .
            "Metric: %s\n" .
            "Value: %.2f\n" .
            "Threshold: %.2f\n" .
            "Severity: %s\n" .
            "Timestamp: %s\n\n" .
            "Tags: %s",
            $metric->getKey(),
            $metric->getValue(),
            $alert->getThreshold()->getValue(),
            $alert->getThreshold()->getSeverity(),
            date('Y-m-d H:i:s', (int)$alert->getTimestamp()),
            json_encode($metric->getTags(), JSON_PRETTY_PRINT)
        );
    }

    private function formatAttachments(PerformanceAlert $alert): array 
    {
        return [
            [
                'title' => 'Metric Details',
                'fields' => $this->formatMetricFields($alert->getMetric())
            ],
            [
                'title' => 'Threshold Information',
                'fields' => $this->formatThresholdFields($alert->getThreshold())
            ]
        ];
    }

    private function formatMetricFields(PerformanceMetric $metric): array 
    {
        return [
            [
                'title' => 'Key',
                'value' => $metric->getKey(),
                'short' => true
            ],
            [
                'title' => 'Value',
                'value' => number_format($metric->getValue(), 2),
                'short' => true
            ],
            [
                'title' => 'Tags',
                'value' => json_encode($metric->getTags()),
                'short' => false
            ]
        ];
    }

    private function formatThresholdFields(Threshold $threshold): array 
    {
        return [
            [
                'title' => 'Threshold Value',
                'value' => number_format($threshold->getValue(), 2),
                'short' => true
            ],
            [
                'title' => 'Severity',
                'value' => $threshold->getSeverity(),
                'short' => true
            ]
        ];
    }
}

class AlertLogger {
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger) 
    {
        $this->logger = $logger;
    }

    public function logAlert(PerformanceAlert $alert): void 
    {
        $this->logger->warning('Performance threshold exceeded', [
            'metric_key' => $alert->getMetric()->getKey(),
            'value' => $alert->getMetric()->getValue(),
            'threshold' => $alert->getThreshold()->getValue(),
            'severity' => $alert->getThreshold()->getSeverity(),
            'timestamp' => $alert->getTimestamp(),
            'tags' => $alert->getMetric()->getTags()
        ]);
    }
}

class AlertFilter {
    private array $rules;

    public function __construct(array $rules) 
    {
        $this->rules = $rules;
    }

    public function shouldProcess(PerformanceAlert $alert): bool 
    {
        foreach ($this->rules as $rule) {
            if (!$rule->evaluate($alert)) {
                return false;
            }
        }
        
        return true;
    }
}

interface AlertRule {
    public function evaluate(PerformanceAlert $alert): bool;
}

class SeverityRule implements AlertRule {
    private array $severities;

    public function __construct(array $severities) 
    {
        $this->severities = $severities;
    }

    public function evaluate(PerformanceAlert $alert): bool 
    {
        return in_array($alert->getThreshold()->getSeverity(), $this->severities);
    }
}

class ThresholdPercentageRule implements AlertRule {
    private float $percentage;

    public function __construct(float $percentage) 
    {
        $this->percentage = $percentage;
    }

    public function evaluate(PerformanceAlert $alert): bool 
    {
        $value = $alert->getMetric()->getValue();
        $threshold = $alert->getThreshold()->getValue();
        
        return ($value / $threshold) >= $this->percentage;
    }
}
