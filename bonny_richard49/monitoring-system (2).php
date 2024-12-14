<?php

namespace App\Core\Monitoring;

class SystemMonitor implements MonitorInterface
{
    private SecurityManager $security;
    private EventDispatcher $events;
    private MetricsCollector $metrics;
    private AuditLogger $logger;

    public function monitorOperation(Operation $operation): Result
    {
        return DB::transaction(function() use ($operation) {
            $context = new MonitoringContext($operation);
            
            $this->startMonitoring($context);
            
            try {
                $result = $operation->execute();
                $this->recordSuccess($context, $result);
                return $result;
            } catch (\Throwable $e) {
                $this->handleFailure($context, $e);
                throw $e;
            } finally {
                $this->completeMonitoring($context);
            }
        });
    }

    private function startMonitoring(MonitoringContext $context): void
    {
        $this->security->validateMonitoringContext($context);
        $this->metrics->startOperation($context);
        $this->events->dispatch(new MonitoringStarted($context));
    }

    private function recordSuccess(MonitoringContext $context, $result): void
    {
        $this->metrics->recordSuccess($context);
        $this->logger->logSuccess($context, $result);
        $this->events->dispatch(new OperationSucceeded($context));
    }

    private function handleFailure(MonitoringContext $context, \Throwable $e): void
    {
        $this->metrics->recordFailure($context, $e);
        $this->logger->logFailure($context, $e);
        $this->events->dispatch(new OperationFailed($context, $e));
    }

    private function completeMonitoring(MonitoringContext $context): void
    {
        $this->metrics->endOperation($context);
        $this->logger->logCompletion($context);
        $this->events->dispatch(new MonitoringCompleted($context));
    }
}