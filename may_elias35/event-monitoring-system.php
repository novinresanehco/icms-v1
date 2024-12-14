// File: app/Core/Event/Monitoring/EventMonitor.php
<?php

namespace App\Core\Event\Monitoring;

class EventMonitor
{
    protected MetricsCollector $metrics;
    protected AlertManager $alerts;
    protected MonitorConfig $config;

    public function monitor(Event $event): void
    {
        // Collect metrics
        $this->collectMetrics($event);
        
        // Check thresholds
        $this->checkThresholds($event);
        
        // Monitor performance
        $this->monitorPerformance($event);
    }

    protected function collectMetrics(Event $event): void
    {
        $this->metrics->record([
            'event_name' => $event->getName(),
            'processing_time' => $event->getProcessingTime(),
            'memory_usage' => $event->getMemoryUsage(),
            'listener_count' => $event->getListenerCount()
        ]);
    }

    protected function checkThresholds(Event $event): void
    {
        if ($event->getProcessingTime() > $this->config->getMaxProcessingTime()) {
            $this->alerts->dispatch(new ProcessingTimeAlert($event));
        }

        if ($event->getMemoryUsage() > $this->config->getMaxMemoryUsage()) {
            $this->alerts->dispatch(new MemoryUsageAlert($event));
        }
    }
}
