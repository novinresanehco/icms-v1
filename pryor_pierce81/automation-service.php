<?php

namespace App\Core\Automation;

class AutomationController implements AutomationInterface
{
    private TaskScheduler $scheduler;
    private ProcessManager $processManager;
    private WorkflowEngine $workflowEngine;
    private ValidationService $validator;
    private AutomationLogger $logger;
    private AlertSystem $alerts;

    public function __construct(
        TaskScheduler $scheduler,
        ProcessManager $processManager,
        WorkflowEngine $workflowEngine,
        ValidationService $validator,
        AutomationLogger $logger,
        AlertSystem $alerts
    ) {
        $this->scheduler = $scheduler;
        $this->processManager = $processManager;
        $this->workflowEngine = $workflowEngine;
        $this->validator = $validator;
        $this->logger = $logger;
        $this->alerts = $alerts;
    }

    public function executeAutomation(AutomationTask $task): ExecutionResult
    {
        DB::beginTransaction();
        try {
            $this->validator->validateTask($task);
            $process = $this->processManager->createProcess($task);
            
            $workflow = $this->workflowEngine->executeWorkflow($process);
            
            if (!$workflow->isSuccessful()) {
                throw new WorkflowException(
                    'Workflow execution failed',
                    $workflow->getErrors()
                );
            }

            $this->logger->logExecution($workflow);
            DB::commit();
            
            return new ExecutionResult(true, $workflow);

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleExecutionFailure($e, $task);
            throw new AutomationException($e->getMessage(), $e);
        }
    }

    public function scheduleTask(AutomationTask $task, Schedule $schedule): void
    {
        try {
            $this->validator->validateSchedule($task, $schedule);
            $this->scheduler->schedule($task, $schedule);
            $this->logger->logScheduling($task, $schedule);
        } catch (\Exception $e) {
            $this->handleSchedulingFailure($e, $task);
            throw new SchedulingException($e->getMessage(), $e);
        }
    }

    private function handleExecutionFailure(\Exception $e, AutomationTask $task): void
    {
        $this->logger->logFailure($e, $task);
        $this->alerts->dispatch(
            new AutomationAlert(
                'Automation execution failed',
                [
                    'task' => $task,
                    'exception' => $e
                ]
            )
        );
    }

    private function handleSchedulingFailure(\Exception $e, AutomationTask $task): void 
    {
        $this->logger->logSchedulingFailure($e, $task);
        $this->alerts->dispatch(
            new SchedulingAlert(
                'Task scheduling failed',
                [
                    'task' => $task,
                    'exception' => $e
                ]
            )
        );
    }
}
