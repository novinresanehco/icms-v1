<?php

namespace App\Core\Notification\Analytics\Services;

use App\Core\Notification\Analytics\Models\AlertConfiguration;
use App\Core\Notification\Analytics\Policies\AlertThrottlingPolicy;
use Illuminate\Support\Collection;
use Carbon\Carbon;

class AlertPolicyService
{
    private AlertThrottlingPolicy $throttlingPolicy;
    private array $severityLevels = [
        'critical' => 3,
        'warning' => 2,
        'info' => 1
    ];

    private array $channelPolicies = [
        'slack' => [
            'critical' => true,
            'warning' => true,
            'info' => false
        ],
        'email' => [
            'critical' => true,
            'warning' => true,
            'info' => false
        ],
        'sms' => [
            'critical' => true,
            'warning' => false,
            'info' => false
        ]
    ];

    public function __construct(AlertThrottlingPolicy $throttlingPolicy)
    {
        $this->throttlingPolicy = $throttlingPolicy;
    }

    public function shouldSendAlert(string $metricName, string $severity, float $value): bool
    {
        // Check if severity level warrants an alert
        if (!$this->isValidSeverityLevel($severity)) {
            return false;
        }

        // Check if metric is being throttled
        if ($this->throttlingPolicy->shouldThrottleAlert($metricName, $severity)) {
            return false;
        }

        // Get alert configuration
        $config = AlertConfiguration::forMetric($metricName)
            ->forSeverity($severity)
            ->first();

        // If no configuration exists, default to sending critical alerts only
        if (!$config) {
            return $severity === 'critical';
        }

        // Check if alert is active and threshold is exceeded
        return $config->is_active && $this->isThresholdExceeded($value, $config->threshold);
    }

    public function getEnabledChannels(string $severity): array
    {
        $channels = [];

        foreach ($this->channelPolicies as $channel => $policies) {
            if ($policies[$severity] ?? false) {
                $channels[] = $channel;
            }
        }

        return $channels;
    }

    public function getRecipientsByChannel(AlertConfiguration $config, string $channel): Collection
    {
        $recipients = collect($config->getRecipientsByChannel($channel));

        return $recipients->filter(function ($recipient) use ($config) {
            return $this->isRecipientEligible($recipient, $config);
        });
    }

    public function getSeverityLevel(string $severity): int 
    {
        return $this->severityLevels[$severity] ?? 0;
    }

    public function isEscalationRequired(AlertConfiguration $config, int $occurrences): bool
    {
        $escalationThreshold = $config->metadata['escalation_threshold'] ?? PHP_INT_MAX;
        return $occurrences >= $escalationThreshold;
    }

    public function shouldSuppressDuplicates(AlertConfiguration $config): bool
    {
        return $config->metadata['suppress_duplicates'] ?? false;
    }

    public function getAlertCooldown(AlertConfiguration $config): int
    {
        return $config->throttle_minutes ?? 30;
    }

    private function isValidSeverityLevel(string $severity): bool
    {
        return isset($this->severityLevels[$severity]);
    }

    private function isThresholdExceeded(float $value, float $threshold): bool
    {
        return $value >= $threshold;
    }

    private function isRecipientEligible($recipient, AlertConfiguration $config): bool
    {
        // Add custom logic for recipient eligibility
        // For example, checking work hours, on-call schedules, etc.
        return true;
    }

    public function handleAlertEscalation(
        AlertConfiguration $config, 
        string $metricName, 
        float $value,
        int $occurrences
    ): void {
        if (!$this->isEscalationRequired($config, $occurrences)) {
            return;
        }

        $escalationConfig = $config->metadata['escalation'] ?? [];
        $nextLevel = $this->getNextEscalationLevel($escalationConfig, $occurrences);

        if ($nextLevel) {
            $this->triggerEscalation($config, $nextLevel, [
                'metric_name' => $metricName,
                'value' => $value,
                'occurrences' => $occurrences,
                'previous_level' => $config->severity
            ]);
        }
    }

    private function getNextEscalationLevel(array $escalationConfig, int $occurrences): ?string
    {
        foreach ($escalationConfig as $level => $threshold) {
            if ($occurrences >= $threshold) {
                return $level;
            }
        }

        return null;
    }

    private function triggerEscalation(AlertConfiguration $config, string $level, array $context): void
    {
        event(new AlertEscalatedEvent($config, $level, $context));
    }

    public function validateAlertPolicy(array $policy): array
    {
        $errors = [];

        // Validate severity
        if (!isset($policy['severity']) || !$this->isValidSeverityLevel($policy['severity'])) {
            $errors[] = 'Invalid severity level';
        }

        // Validate threshold
        if (!isset($policy['threshold']) || !is_numeric($policy['threshold'])) {
            $errors[] = 'Invalid threshold value';
        }

        // Validate channels
        if (isset($policy['channels'])) {
            foreach ($policy['channels'] as $channel) {
                if (!isset($this->channelPolicies[$channel])) {
                    $errors[] = "Invalid channel: {$channel}";
                }
            }
        }

        // Validate throttling
        if (isset($policy['throttle_minutes']) && (!is_numeric($policy['throttle_minutes']) || $policy['throttle_minutes'] < 0)) {
            $errors[] = 'Invalid throttle duration';
        }

        return $errors;
    }
}
