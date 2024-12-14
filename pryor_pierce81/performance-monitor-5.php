<?php

namespace App\Core\Security\Monitoring;

class PerformanceMonitor implements PerformanceMonitorInterface
{
    private ResourceTracker $resourceTracker;
    private ResponseTimeAnalyzer $responseAnalyzer;
    private ThroughputMonitor $throughputMonitor;
    private BottleneckDetector $bottleneckDetector;
    private PerformanceLogger $performanceLogger;
    private AlertSystem $alertSystem;

    public function __construct(
        ResourceTracker $resourceTracker,
        ResponseTimeAnalyzer $responseAnalyzer,
        ThroughputMonitor $throughputMonitor,
        BottleneckDetector $bottleneckDetector,
        PerformanceLogger $performanceLogger,
        AlertSystem $alertSystem
    ) {
        $this->resourceTracker = $resourceTracker;
        $this->responseAnalyzer = $responseAnalyzer;
        $this->throughputMonitor = $throughputMonitor;
        $this->bottleneckDetector = $bottleneckDetector;
        $this->performanceLogger = $performanceLogger;
        $this->alertSystem = $alertSystem;
    }

    public function startTracking(MonitoringSession $session): void
    {
        try {
            $this->initializeMonitors($session);
            $this->startMonitors($session);
            $this->performanceLogger->logTrackingStart($session);
        } catch (MonitoringException $e) {
            $this->handleStartupFailure($session, $e);
            throw $e;
        }
    }

    public function trackPerformance(OperationContext $context): PerformanceMetrics
    {
        try {
            $resources = $this->resourceTracker->track();
            $responseTimes = $this->responseAnalyzer->analyze();
            $throughput = $this->throughputMonitor->measure();
            $bottlenecks = $this->bottleneckDetector->detect();

            $metrics = new PerformanceMetrics([
                'resources' => $resources,
                'response_times' => $responseTimes,
                'throughput' => $throughput,
                'bottlenecks' => $bottlenecks
            ]);

            $this->validateMetrics($metrics, $context);
            $this->logMetrics($metrics, $context);

            return $metrics;

        } catch (TrackingException $e) {
            $this->handleTrackingFailure($e, $context);
            throw $e;
        }
    }

    public function stopTracking(MonitoringSession $session): void
    {
        try {
            $this->stopMonitors($session);
            $this->collectFinalMetrics($session);
            $this->performanceLogger->logTrackingStop($session);
        } catch (MonitoringException $e) {
            $this->handleShutdownFailure($session, $e);
            throw $e;
        }
    }

    private function validateMetrics(PerformanceMetrics $metrics, OperationContext $context): void
    {
        if ($metrics->responseTime > $context->getMaxResponseTime()) {
            $this->alertSystem->generateAlert(new PerformanceAlert(
                type: AlertType::RESPONSE_TIME_EXCEEDED,
                metrics: $metrics,
                context: $context
            ));
        }

        if ($metrics->resourceUsage > $context->getMaxResourceUsage()) {
            $this->alertSystem->generateAlert(new PerformanceAlert(
                type: AlertType::RESOURCE_USAGE_EXCEEDED,
                metrics: $metrics,
                context: $context
            ));
        }
    }

    private function collectFinalMetrics(MonitoringSession $session): void
    {
        $metrics = [
            'resources' => $this->resourceTracker->getFinalMetrics(),
            'response_times' => $this->responseAnalyzer->getFinalMetrics(),
            'throughput' => $this->throughputMonitor->getFinalMetrics(),
            'bottlenecks' => $this->bottleneckDetector->getFinalMetrics()
        ];

        $this->performanceLogger->logFinalMetrics($metrics, $session);
    }
}
