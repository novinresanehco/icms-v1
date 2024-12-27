<?php

namespace App\Core\Metrics\Alerts;

class AlertManager implements AlertManagerInterface
{
    private AlertStorage $storage;
    private NotificationService $notifications;
    private ThresholdValidator $validator;

    public function __construct(
        AlertStorage $storage,
        NotificationService $notifications,
        ThresholdValidator $validator
    ) {
        $this->storage = $storage;
        $this->notifications = $notifications;
        $this->validator = $validator;
    }

    public function checkMetric(string $metric, float $value): void
    {
        $thresholds = $this->storage->getThresholds($metric);

        foreach ($thresholds as $threshold) {
            if (!$this->validator->isValid($value, $threshold)) {
                $this->handleThresholdViolation($metric, $value, $threshold);
            }
        }
    }

    public function addThreshold(string $metric, array $config): void
    {
        $this->validator->validateConfig($config);
        $this->storage->storeThreshold($metric, $config);
    }

    private function handleThresholdViolation(string $metric, float $value, array $threshold): void
    {
        $alert = [
            'metric' => $metric,
            'value' => $value,
            'threshold' => $threshold['value'],
            'condition' => $threshold['condition'],
            'severity' => $threshold['severity'],
            'timestamp' => now()
        ];

        $this->storage->storeAlert($alert);
        
        if ($threshold['notify']) {
            $this->notifications->send(
                $threshold['channels'],
                $this->formatAlertMessage($alert)
            );
        }
    }

    private function formatAlertMessage(array $alert): string
    {
        return sprintf(
            'Alert: %s %s %s (current value: %s)',
            $alert['metric'],
            $alert['condition'],
            $alert['threshold'],
            $alert['value']
        );
    }
}

class AlertStorage
{
    public function getThresholds(string $metric): array
    {
        return DB::table('alert_thresholds')
            ->where('metric', $metric)
            ->where('active', true)
            ->get()
            ->toArray();
    }

    public function storeThreshold(string $metric, array $config): void
    {
        DB::table('alert_thresholds')->insert([
            'metric' => $metric,
            'condition' => $config['condition'],
            'value' => $config['value'],
            'severity' => $config['severity'],
            'notify' => $config['notify'],
            'channels' => json_encode($config['channels']),
            'created_at' => now()
        ]);
    }

    public function storeAlert(array $alert): void
    {
        DB::table('alerts')->insert([
            'metric' => $alert['metric'],
            'value' => $alert['value'],
            'threshold' => $alert['threshold'],
            'condition' => $alert['condition'],
            'severity' => $alert['severity'],
            'created_at' => $alert['timestamp']
        ]);
    }
}

class ThresholdValidator
{
    public function isValid(float $value, array $threshold): bool
    {
        return match($threshold['condition']) {
            '>' => $value > $threshold['value'],
            '>=' => $value >= $threshold['value'],
            '<' => $value < $threshold['value'],
            '<=' => $value <= $threshold['value'],
            '=' => $value == $threshold['value'],
            default => throw new \InvalidArgumentException('Invalid condition')
        };
    }

    public function validateConfig(array $config): void
    {
        $required = ['condition', 'value', 'severity', 'notify', 'channels'];
        
        foreach ($required as $field) {
            if (!isset($config[$field])) {
                throw new ValidationException("Missing required field: {$field}");
            }
        }

        $this->validateCondition($config['condition']);
        $this->validateSeverity($config['severity']);
        $this->validateChannels($config['channels']);
    }

    private function validateCondition(string $condition): void
    {
        $valid = ['>', '>=', '<', '<=', '='];
        
        if (!in_array($condition, $valid)) {
            throw new ValidationException('Invalid condition');
        }
    }

    private function validateSeverity(string $severity): void
    {
        $valid = ['critical', 'warning', 'info'];
        
        if (!in_array($severity, $valid)) {
            throw new ValidationException('Invalid severity level');
        }
    }

    private function validateChannels(array $channels): void
    {
        $valid = ['email', 'slack', 'sms'];
        
        foreach ($channels as $channel) {
            if (!in_array($channel, $valid)) {
                throw new ValidationException("Invalid notification channel: {$channel}");
            }
        }
    }
}

interface NotificationService
{
    public function send(array $channels, string $message): void;
}