<?php

namespace App\Core\Notification\Analytics\Listeners;

use App\Core\Notification\Analytics\Events\{
    NotificationDeliveredEvent,
    NotificationOpenedEvent,
    NotificationClickedEvent,
    NotificationConvertedEvent,
    AnomalyDetectedEvent
};
use App\Core\Notification\Analytics\Services\{
    MetricsProcessorService,
    AnomalyDetectionService,
    AlertingService
};
use App\Core\Notification\Analytics\Models\NotificationDeliveryMetrics;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class NotificationMetricsListener implements ShouldQueue
{
    private MetricsProcessorService $metricsProcessor;
    private AnomalyDetectionService $anomalyDetector;
    private AlertingService $alertingService;

    public function __construct(
        MetricsProcessorService $metricsProcessor,
        AnomalyDetectionService $anomalyDetector,
        AlertingService $alertingService
    ) {
        $this->metricsProcessor = $metricsProcessor;
        $this->anomalyDetector = $anomalyDetector;
        $this->alertingService = $alertingService;
    }

    public function handleDelivery(NotificationDeliveredEvent $event): void
    {
        try {
            $metrics = NotificationDeliveryMetrics::firstOrCreate(
                ['notification_id' => $event->notificationId],
                ['sent_at' => now()]
            );

            $metrics->markDelivered();
            
            $processedMetrics = $this->metricsProcessor->processDeliveryMetrics($metrics);
            $this->checkForAnomalies($processedMetrics);

        } catch (\Exception $e) {
            Log::error('Failed to process notification delivery metrics', [
                'notification_id' => $event->notificationId,
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }

    public function handleOpen(NotificationOpenedEvent $event): void
    {
        try {
            $metrics = NotificationDeliveryMetrics::where('notification_id', $event->notificationId)
                ->firstOrFail();

            $metrics->markOpened();
            
            $processedMetrics = $this->metricsProcessor->processDeliveryMetrics($metrics);
            $this->checkForAnomalies($processedMetrics);

        } catch (\Exception $e) {
            Log::error('Failed to process notification open metrics', [
                'notification_id' => $event->notificationId,
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }

    public function handleClick(NotificationClickedEvent $event): void
    {
        try {
            $metrics = NotificationDeliveryMetrics::where('notification_id', $event->notificationId)
                ->firstOrFail();

            $metrics->markClicked();
            
            $processedMetrics = $this->metricsProcessor->processDeliveryMetrics($metrics);
            $this->checkForAnomalies($processedMetrics);

        } catch (\Exception $e) {
            Log::error('Failed to process notification click metrics', [
                'notification_id' => $event->notificationId,
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }

    public function handleConversion(NotificationConvertedEvent $event): void
    {
        try {
            $metrics = NotificationDeliveryMetrics::where('notification_id', $event->notificationId)
                ->firstOrFail();

            $metrics->markConverted();
            
            $processedMetrics = $this->metricsProcessor->processDeliveryMetrics($metrics);
            $this->checkForAnomalies($processedMetrics);

        } catch (\Exception $e) {
            Log::error('Failed to process notification conversion metrics', [
                'notification_id' => $event->notificationId,
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }

    public function handleAnomaly(AnomalyDetectedEvent $event): void
    {
        try {
            $this->alertingService->sendAlert(
                $event->metricName,
                $event->currentValue,
                $event->threshold,
                $event->severity,
                $event->context
            );

        } catch (\Exception $e) {
            Log::error('Failed to handle metric anomaly', [
                'metric' => $event->metricName,
                'error' => $e->getMessage()
            ]);
        }
    }

    private function checkForAnomalies(array $metrics): void
    {
        foreach ($metrics as $metricName => $value) {
            $anomaly = $this->anomalyDetector->detectAnomaly($metricName, $value);
            
            if ($anomaly) {
                event(new AnomalyDetectedEvent(
                    $metricName,
                    $value,
                    $anomaly['threshold'],
                    $anomaly['severity'],
                    $anomaly['context']
                ));
            }
        }
    }
}
