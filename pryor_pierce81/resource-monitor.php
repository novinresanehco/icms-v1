<?php

namespace App\Core\Security\Monitoring;

class ResourceMonitor implements ResourceMonitorInterface
{
    private SystemStateTracker $stateTracker;
    private UsageAnalyzer $usageAnalyzer;
    private ResourceValidator $validator;
    private ThresholdManager $thresholds;
    private ResourceLogger $logger;
    private AlertDispatcher $alerts;

    public function __construct(
        SystemStateTracker $stateTracker,
        UsageAnalyzer $usageAnalyzer,
        ResourceValidator $validator,
        ThresholdManager $thresholds,
        ResourceLogger $logger,
        AlertDispatcher $alerts
    ) {
        $this->stateTracker = $stateTracker;
        $this->usageAnalyzer = $usageAnalyzer;
        $this->validator = $validator;
        $this->thresholds = $thresholds;
        $this->logger = $logger;
        $this->alerts = $alerts;
    }

    public function startMonitoring(MonitoringContext $context): void
    {
        DB::beginTransaction();
        try {
            $this->stateTracker->initialize($context);
            $this->thresholds->configure($context->getThresholds());
            $this->logger->beginSession($context);
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleInitializationFailure($e, $context);
            throw new MonitoringInitializationException($e->getMessage(), $e);
        }
    }

    public function checkResources(): ResourceState
    {
        $state = $this->stateTracker->captureState();
        $analysis = $this->usageAnalyzer->analyze($state);
        
        if (!$this->validator->validateState($state, $analysis)) {
            $this->handleValidationFailure($state, $analysis);
        }

        $this->checkThresholds($analysis);
        $this->logger->logState($state, $analysis);
        
        return new ResourceState($state, $analysis);
    }

    public function stopMonitoring(string $sessionId): void
    {
        DB::beginTransaction();
        try {
            $finalState = $this->stateTracker->getFinalState();
            $this->logger->endSession($sessionId, $finalState);
            $this->stateTracker->cleanup();
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleShutdownFailure($e, $sessionId);
            throw new MonitoringShutdownException($e->getMessage(), $e);
        }
    }

    private function checkThresholds(ResourceAnalysis $analysis): void
    {
        foreach ($analysis->getMetrics() as $metric => $value) {
            if ($this->thresholds->isExceeded($metric, $value)) {
                $this->alerts->dispatch(
                    new ThresholdAlert($metric, $value, $this->thresholds->getLimit($metric))
                );
            }
        }
    }

    private function handleValidationFailure(
        array $state,
        ResourceAnalysis $analysis
    ): void {
        $this->logger->logValidationFailure($state, $analysis);
        $this->alerts->dispatch(
            new ValidationAlert('Resource state validation failed', $state)
        );
    }

    private function handleInitializationFailure(
        \Exception $e,
        MonitoringContext $context
    ): void {
        $this->logger->logInitializationFailure($e, $context);
        $this->alerts->dispatch(
            new SystemAlert('Resource monitoring initialization failed', $e)
        );
    }
}
