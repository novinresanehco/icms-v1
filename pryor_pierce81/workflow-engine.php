<?php

namespace App\Core\Workflow;

class WorkflowEngine implements WorkflowInterface
{
    private StateManager $stateManager;
    private TransitionValidator $validator;
    private ExecutionEngine $executor;
    private CompensationHandler $compensator;
    private WorkflowLogger $logger;
    private AlertSystem $alerts;

    public function __construct(
        StateManager $stateManager,
        TransitionValidator $validator,
        ExecutionEngine $executor,
        CompensationHandler $compensator,
        WorkflowLogger $logger,
        AlertSystem $alerts
    ) {
        $this->stateManager = $stateManager;
        $this->validator = $validator;
        $this->executor = $executor;
        $this->compensator = $compensator;
        $this->logger = $logger;
        $this->alerts = $alerts;
    }

    public function executeWorkflow(WorkflowDefinition $workflow): ExecutionResult
    {
        $transactionId = $this->initializeTransaction($workflow);
        
        try {
            DB::beginTransaction();

            $this->validateWorkflow($workflow);
            $executionPlan = $this->buildExecutionPlan($workflow);
            
            $result = $this->executor->execute($executionPlan);
            
            if (!$result->isSuccessful()) {
                $this->compensator->compensate($executionPlan);
                throw new WorkflowExecutionException('Workflow execution failed');
            }

            $this->stateManager->commitState($result);
            
            DB::commit();
            $this->logger->logSuccess($workflow, $result);
            
            return $result;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleExecutionFailure($e, $workflow, $transactionId);
            throw new CriticalWorkflowException($e->getMessage(), $e);
        }
    }

    private function validateWorkflow(WorkflowDefinition $workflow): void
    {
        $violations = $this->validator->validate($workflow);
        
        if (!empty($violations)) {
            throw new ValidationException(
                'Workflow validation failed',
                ['violations' => $violations]
            );
        }
    }

    private function buildExecutionPlan(WorkflowDefinition $workflow): ExecutionPlan
    {
        return new ExecutionPlan([
            'states' => $this->stateManager->getStates($workflow),
            'transitions' => $this->validator->getValidTransitions($workflow),
            'compensations' => $this->compensator->buildCompensationPlan($workflow)
        ]);
    }

    private function handleExecutionFailure(
        \Exception $e,
        WorkflowDefinition $workflow,
        string $transactionId
    ): void {
        $this->logger->logFailure($e, $workflow, $transactionId);
        
        $this->alerts->dispatch(
            new WorkflowAlert(
                'Critical workflow failure',
                [
                    'workflow' => $workflow,
                    'transaction' => $transactionId,
                    'exception' => $e
                ]
            )
        );
        
        $this->compensator->initiateRecovery($workflow, $transactionId);
    }
}
