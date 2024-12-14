<?php

namespace App\Core\Notification\Analytics\Events;

use App\Core\Notification\Analytics\Models\AlertConfiguration;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AlertEscalatedEvent
{
    use Dispatchable, SerializesModels;

    public AlertConfiguration $config;
    public string $newLevel;
    public array $context;

    public function __construct(AlertConfiguration $config, string $newLevel, array $context)
    {
        $this->config = $config;
        $this->newLevel = $newLevel;
        $this->context = $context;
    }
}

class AlertThresholdUpdatedEvent
{
    use Dispatchable, SerializesModels;

    public AlertConfiguration $config;
    public float $oldThreshold;
    public float $newThreshold;
    public array $metadata;

    public function __construct(
        AlertConfiguration $config,
        float $oldThreshold,
        float $newThreshold,
        array $metadata = []
    ) {
        $this->config = $config;
        $this->oldThreshold = $oldThreshold;
        $this->newThreshold = $newThreshold;
        $this->metadata = $metadata;
    }
}

class AlertPolicyCreatedEvent
{
    use Dispatchable, SerializesModels;

    public AlertConfiguration $config;
    public array $metadata;

    public function __construct(AlertConfiguration $config, array $metadata = [])
    {
        $this->config = $config;
        $this->metadata = $metadata;
    }
}

class AlertPolicyUpdatedEvent 
{
    use Dispatchable, SerializesModels;

    public AlertConfiguration $config;
    public array $changes;
    public array $metadata;

    public function __construct(AlertConfiguration $config, array $changes, array $metadata = [])
    {
        $this->config = $config;
        $this->changes = $changes;
        $this->metadata = $metadata;
    }
}

class AlertPolicyDeactivatedEvent
{
    use Dispatchable, SerializesModels;

    public AlertConfiguration $config;
    public string $reason;
    public array $metadata;

    public function __construct(AlertConfiguration $config, string $reason, array $metadata = [])
    {
        $this->config = $config;
        $this->reason = $reason;
        $this->metadata = $metadata;
    }
}

class AlertDeliveryFailedEvent
{
    use Dispatchable, SerializesModels;

    public AlertConfiguration $config;
    public string $channel;
    public string $error;
    public array $context;

    public function __construct(
        AlertConfiguration $config,
        string $channel,
        string $error,
        array $context = []
    ) {
        $this->config = $config;
        $this->channel = $channel;
        $this->error = $error;
        $this->context = $context;
    }
}

class AlertThrottledEvent
{
    use Dispatchable, SerializesModels;

    public string $metricName;
    public string $severity;
    public array $context;

    public function __construct(string $metricName, string $severity, array $context = [])
    {
        $this->metricName = $metricName;
        $this->severity = $severity;
        $this->context = $context;
    }
}
