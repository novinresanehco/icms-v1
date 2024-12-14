<?php

namespace App\Core\Notification\Analytics\Events;

use App\Core\Notification\Analytics\Models\NotificationDeliveryMetrics;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MetricsProcessedEvent
{
    use Dispatchable, SerializesModels;

    public NotificationDeliveryMetrics $metrics;
    public array $processedMetrics;

    public function __construct(NotificationDeliveryMetrics $metrics, array $processedMetrics)
    {
        $this->metrics = $metrics;
        $this->processedMetrics = $processedMetrics;
    }
}

class NotificationDeliveredEvent
{
    use Dispatchable, SerializesModels;

    public string $notificationId;
    public array $deliveryData;

    public function __construct(string $notificationId, array $deliveryData)
    {
        $this->notificationId = $notificationId;
        $this->deliveryData = $deliveryData;
    }
}

class NotificationOpenedEvent
{
    use Dispatchable, SerializesModels;

    public string $notificationId;
    public array $openData;

    public function __construct(string $notificationId, array $openData)
    {
        $this->notificationId = $notificationId;
        $this->openData = $openData;
    }
}

class NotificationClickedEvent
{
    use Dispatchable, SerializesModels;

    public string $notificationId;
    public array $clickData;

    public function __construct(string $notificationId, array $clickData)
    {
        $this->notificationId = $notificationId;
        $this->clickData = $clickData;
    }
}

class NotificationConvertedEvent