<?php

namespace App\Core\Threshold;

class ThresholdManagementService implements ThresholdInterface
{
    private ThresholdStore $store;
    private ValidationEngine $validator;
    private BoundaryController $boundaryController;
    private ThresholdLogger $logger;
    private EmergencyProtocol $emergency;
    private AlertSystem $alerts;

    public function __construct(
        ThresholdStore $store,
        ValidationEngine $validator,
        BoundaryController $boundaryController,
        ThresholdLogger $logger,
        EmergencyProtocol $emergency,
        AlertSystem $alerts
    ) {
        $this->store = $store;
        $this->validator = $validator;
        $this->boundaryController = $boundaryController;
        $this->logger = $logger;
        $this->emergency = $emergency;
        $this->alerts = $alerts;
    }

    public function enforceThresholds(ThresholdContext $context): ValidationResult
    {
        $operationId = $this->initializeOperation($context);
        
        try {
            DB::beginTransaction();

            $thresholds = $this->store->getActiveThresholds();
            $this->validateThresholds($thresholds);

            $boundaries = $this->boundaryController->calculateBoundaries($context);
            $this->validateBoundaries($boundaries);

            $validationResult = $this->validator->validateAgainstThresholds(
                $context,
                $thresholds,
                $boundaries
            );

            if ($validationResult->hasViolations()) {
                $this->handleViolations($validationResult->getViolations());
            }

            DB::commit();
            return $validationResult;

        } catch (ThresholdException $e) {
            DB::rollBack();
            $this->handleThresholdFailure($e, $operationId);
            throw new CriticalThresholdException($e->getMessage(), $e);
        }
    }

    private function validateThresholds(array $thresholds): void
    {
        foreach ($thresholds as $threshold) {
            if (!$this->validator->isValidThreshold($threshold)) {
                $this->emergency->handleInvalidThreshold($threshold);
                throw new InvalidThresholdException('Invalid threshold configuration detected');
            }
        }
    }

    private function validateBoundaries(array $boundaries): void
    {
        foreach ($boundaries as $boundary) {
            if ($boundary->isOutOfBounds()) {
                $this->emergency->handleBoundaryViolation($boundary);
                throw new BoundaryViolationException('System boundary violation detected');
            }
        }
    }

    private function handleViolations(array $violations): void
    {
        foreach ($violations as $violation) {
            $this->logger->logViolation($violation);
            $this->alerts->dispatchViolation($violation);

            if ($violation->isCritical()) {
                $this->emergency->handleCriticalViolation($violation);
            }
        }
    }

    private function handleThresholdFailure(
        ThresholdException $e,
        string $operationId
    ): void {
        $this->logger->logFailure($e, $operationId);
        
        if ($e->isCritical()) {
            $this->emergency->escalateToHighestLevel();
            $this->alerts->dispatchCriticalAlert(
                new ThresholdFailureAlert($e, $operationId)
            );
        }
    }
}

