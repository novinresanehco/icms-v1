<?php

namespace App\Core\Automation;

class AutomationControlService implements AutomationControlInterface
{
    private TaskScheduler $scheduler;
    private ProcessValidator $validator;
    private ExecutionEngine $executor;
    private AutomationLogger $logger;
    private EmergencyProtocol $emergency;
    private AlertSystem $alerts;

    public function __construct(
        TaskScheduler $scheduler,
        ProcessValidator $validator,
        ExecutionEngine $executor,
        AutomationLogger $logger,
        EmergencyProtocol $emergency,
        AlertSystem $alerts
    ) {
        $this->scheduler = $scheduler;
        $this->validator = $validator;
        $this->executor = $executor;
        $this->logger = $logger;
        $this->emergency = $emergency;
        $this->alerts = $alerts;
    }

    public function executeAutomation(AutomationContext $context): AutomationResult
    {
        $automationId = $this->initializeAutomation($context);
        
        try {
            DB::beginTransaction();

            $this->validateAutomationContext($context);
            $tasks = $this->scheduler->scheduleTasks($context);
            
            foreach ($tasks as $task) {
                $this->validateTask($task);
                $this->executeTask($task);
            }

            $result = new AutomationResult([
                'automationId' => $automationId,
                'tasks' => $tasks,
                'metrics' => $this->collectMetrics(),
                'status' => AutomationStatus::COMPLETED,
                'timestamp' => now()
            ]);

            DB::commit();
            return $result;

        } catch (AutomationException $e) {
            DB::rollBack();
            $this->handleAutomationFailure($e, $automationId);
            throw new CriticalAutomationException($e->getMessage(), $e);
        }
    }

    private function validateTask(AutomationTask $task): void
    {
        if (!$this->validator->validateTask($task)) {
            throw new TaskValidationException('Task validation failed');
        }

        if ($task->isCritical() && !$this->validator->validateCriticalTask($task)) {
            $this->emergency->handleCriticalTaskFailure($task);
            throw new CriticalTaskException('Critical task validation failed');
        }
    }

    private function executeTask(AutomationTask $task): void
    {
        $execution = $this->executor->execute($task);
        
        if (!$execution->isSuccessful()) {
            if ($task->isCritical()) {
                $this->emergency->handleCriticalExecutionFailure($execution);
            }
            throw new TaskExecutionException('Task execution failed');
        }
    }

    private function handleAutomationFailure(
        AutomationException $e, 
        string $automationId
    ): void {
        $this->logger->logFailure($e, $automationId);
        
        if ($e->isCritical()) {
            $this->emergency->initiateEmergencyProtocol();
            $this->alerts->dispatchCriticalAlert(
                new AutomationFailureAlert($e, $automationId)
            );
        }
    }
}
