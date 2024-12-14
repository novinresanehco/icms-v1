<?php

namespace App\Core\Deployment;

class DeploymentController implements DeploymentInterface
{
    private ValidationChain $validationChain;
    private StateManager $stateManager;
    private RollbackManager $rollbackManager;
    private DeploymentLogger $logger;
    private MetricsCollector $metrics;
    private AlertSystem $alerts;

    public function __construct(
        ValidationChain $validationChain,
        StateManager $stateManager,
        RollbackManager $rollbackManager,
        DeploymentLogger $logger,
        MetricsCollector $metrics,
        AlertSystem $alerts
    ) {
        $this->validationChain = $validationChain;
        $this->stateManager = $stateManager;
        $this->rollbackManager = $rollbackManager;
        $this->logger = $logger;
        $this->metrics = $metrics;
        $this->alerts = $alerts;
    }

    public function executeDeployment(DeploymentRequest $request): DeploymentResult
    {
        $deploymentId = $this->initializeDeployment($request);
        
        try {
            DB::beginTransaction();
            
            $this->validateDeployment($request);
            $currentState = $this->stateManager->captureState();
            
            $rollbackPoint = $this->rollbackManager->createRollbackPoint($currentState);
            
            $newState = $this->performDeployment($request);
            $this->verifyDeployment($newState);
            
            $result = new DeploymentResult([
                'deploymentId' => $deploymentId,
                'previousState' => $currentState,
                'newState' => $newState,
                'timestamp' => now()
            ]);
            
            DB::commit();
            return $result;

        } catch (DeploymentException $e) {
            DB::rollBack();
            $this->handleDeploymentFailure($e, $deploymentId, $rollbackPoint);
            throw new CriticalDeploymentException($e->getMessage(), $e);
        }
    }

    private function validateDeployment(DeploymentRequest $request): void
    {
        $validationResult = $this->validationChain->validate($request);
        
        if (!$validationResult->isPassed()) {
            throw new ValidationException(
                'Deployment validation failed',
                $validationResult->getViolations()
            );
        }
    }

    private function performDeployment(DeploymentRequest $request): SystemState
    {
        $deployment = $this->executeDeploymentSteps($request);
        
        if (!$deployment->isSuccessful()) {
            throw new DeploymentExecutionException('Deployment execution failed');
        }
        
        return $deployment->getResultingState();
    }

    private function verifyDeployment(SystemState $state): void
    {
        $verificationResult = $this->validationChain->verifyState($state);
        
        if (!$verificationResult->isPassed()) {
            throw new DeploymentVerificationException(
                'Deployment verification failed',
                $verificationResult->getViolations()
            );
        }
    }

    private function handleDeploymentFailure(
        DeploymentException $e,
        string $deploymentId,
        RollbackPoint $rollbackPoint
    ): void {
        $this->logger->logFailure($e, $deploymentId);
        
        try {
            $this->rollbackManager->executeRollback($rollbackPoint);
        } catch (RollbackException $re) {
            $this->alerts->dispatch(
                new CriticalAlert(
                    'Rollback failed during deployment failure handling',
                    ['exception' => $re]
                )
            );
        }
        
        $this->alerts->dispatch(
            new DeploymentAlert(
                'Critical deployment failure',
                [
                    'deploymentId' => $deploymentId,
                    'exception' => $e
                ]
            )
        );
    }
}
