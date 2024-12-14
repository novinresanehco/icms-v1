<?php

namespace App\Core\Metrics\Alerts;

class AlertManager
{
    private array $handlers = [];
    private array $thresholds = [];
    private array $suppressedAlerts = [];

    public function registerHandler(string $type, AlertHandlerInterface $handler): void
    {
        $this->handlers[$type] = $handler;
    }

    public function setThreshold(string $metric, float $threshold, string $condition = '>'): void
    {
        $this->thresholds[$metric] = [
            'value' => $threshold,
            'condition' => $condition
        ];
    }

    public function check(string $metric, $value, array $context = []): void
    {
        if (!isset($this->thresholds[$metric]) || $this->isAlertSuppressed($metric)) {
            return;
        }

        $threshold = $this->thresholds[$metric];
        if ($this->isThresholdExceeded($value, $threshold)) {
            $this->triggerAlert($metric, $value, $threshold, $context);
        }
    }

    private function isThresholdExceeded($value, array $threshold): bool
    {
        return match($threshold['condition']) {
            '>' => $value > $threshold['value'],
            '<' => $value < $threshold['value'],
            '>=' => $value >= $threshold['value'],
            '<=' => $value <= $threshold['value'],
            '=' => $value == $threshold['value'],
            default => false
        };
    }

    private function triggerAlert(string $metric, $value, array $threshold, array $context): void
    {
        $alert = new Alert(
            metric: $metric,
            value: $value,
            threshold: $threshold['value'],
            context: $context
        );

        foreach ($this->handlers as $handler) {
            $handler->handle($alert);
        }
    }

    private function isAlertSuppressed(string $metric): bool
    {
        return isset($this->suppressedAlerts[$metric]) && 
               $this->suppressedAlerts[$metric] > time();
    }
}

class Alert
{
    public function __construct(
        public readonly string $metric,
        public readonly mixed $value,
        public readonly float $threshold,
        public readonly array $context = [],
        public readonly int $timestamp = null
    ) {
        $this->timestamp = $timestamp ?? time();
    }
}

interface AlertHandlerInterface
{
    public function handle(Alert $alert): void;
}

class SlackAlertHandler implements AlertHandlerInterface
{
    private string $webhookUrl;
    
    public function __construct(string $webhookUrl)
    {
        $this->webhookUrl = $webhookUrl;
    }

    public function handle(Alert $alert): void
    {
        $payload = [
            'text' => sprintf(
                "🚨 Alert: %s exceeded threshold (%s)\nCurrent value: %s\nContext: %s",
                $alert->metric,
                $alert->threshold,
                $alert->value,
                json_encode($alert->context)
            )
        ];

        $this->sendToSlack($payload);
    }

    private function sendToSlack(array $payload): void
    {
        $ch = curl_init($this->webhookUrl);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen(json_encode($payload))
        ]);
        
        curl_exec($ch);
        curl_close($ch);
    }
}
