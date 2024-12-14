<?php

namespace App\Core\Runtime;

class RuntimeValidator implements RuntimeValidationInterface
{
    private StateValidator $stateValidator;
    private ExecutionMonitor $executionMonitor;
    private ResourceTracker $resourceTracker;
    private ConstraintValidator $constraintValidator;
    private RuntimeLogger $logger;
    private AlertSystem $alerts;

    public function __construct(
        StateValidator $stateValidator,
        ExecutionMonitor $executionMonitor,
        ResourceTracker $resourceTracker,
        ConstraintValidator $constraintValidator,
        RuntimeLogger $logger,
        AlertSystem $alerts
    ) {
        $this->stateValidator = $stateValidator;
        $this->executionMonitor = $executionMonitor;
        $this->resourceTracker = $resourceTracker;
        $this->constraintValidator = $constraintValidator;
        $this->logger = $logger;
        $this->alerts = $alerts;
    }

    public function validateRuntime(RuntimeContext $context): RuntimeResult
    {
        $validationId = $this->initializeValidation($context);
        
        try {
            DB::beginTransaction();

            $this->validateState($context);
            $this->monitorExecution($context);
            $this->trackResources($context);
            $this->validateConstraints($context);

            $result = new RuntimeResult([
                'validationId' => $validationId,
                'status' => RuntimeStatus::VALID,
                'metrics' => $this->collectRuntimeMetrics(),
                'timestamp' => now()
            ]);

            DB::commit();
            $this->finalizeValidation($result);
            
            return $result;

        } catch (RuntimeException $e) {
            DB::rollBack();
            $this->handleValidationFailure($e, $validationId);
            throw new CriticalRuntimeException($e->getMessage(), $e);
        }
    }

    private function validateState(RuntimeContext $context): void
    {
        $stateIssues = $this->stateValidator->validate($context);
        
        if (!empty($stateIssues)) {
            throw new StateValidationException(
                'Runtime state validation failed',
                ['issues' => $stateIssues]
            );
        }
    }

    private function monitorExecution(RuntimeContext $context): void
    {
        $executionIssues = $this->executionMonitor->monitor($context);
        
        if (!empty($executionIssues)) {
            throw new ExecutionException(
                'Runtime execution monitoring failed',
                ['issues' => $executionIssues]
            );
        }
    }

    private function validateConstraints(RuntimeContext $context): void
    {
        $constraintViolations = $this->constraintValidator->validate($context);
        
        if (!empty($constraintViolations)) {
            throw new ConstraintViolationException(
                'Runtime constraints violated',
                ['violations' => $constraintViolations]
            );
        }
    }

    private function collectRuntimeMetrics(): array
    {
        return [
            'state' => $this->stateValidator->getMetrics(),
            'execution' => $this->executionMonitor->getMetrics(),
            'resources' => $this->resourceTracker->getMetrics(),
            'constraints' => $this->constraintValidator->getMetrics()
        ];
    }
}
