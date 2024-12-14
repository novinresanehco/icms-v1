<?php

namespace App\Core\Response;

class ResponseCoordinationService implements ResponseCoordinatorInterface
{
    private ResponseValidator $validator;
    private ActionController $actionController;
    private ResponseMonitor $monitor;
    private DecisionEngine $decisionEngine;
    private ResponseLogger $logger;
    private EmergencyProtocol $emergency;

    public function __construct(
        ResponseValidator $validator,
        ActionController $actionController,
        ResponseMonitor $monitor,
        DecisionEngine $decisionEngine,
        ResponseLogger $logger,
        EmergencyProtocol $emergency
    ) {
        $this->validator = $validator;
        $this->actionController = $actionController;
        $this->monitor = $monitor;
        $this->decisionEngine = $decisionEngine;
        $this->logger = $logger;
        $this->emergency = $emergency;
    }

    public function coordinateResponse(ResponseContext $context): ResponseResult
    {
        $responseId = $this->initializeResponse($context);
        
        try {
            DB::beginTransaction();

            $this->validateResponseContext($context);
            $decision = $this->decisionEngine->analyze($context);
            $this->validateDecision($decision);

            $actions = $this->actionController->determineActions($decision);
            $this->validateActions($actions);

            $execution = $this->executeActions($actions, $context);
            $this->monitorExecution($execution);

            $result = new ResponseResult([
                'responseId' => $responseId,
                'decision' => $decision,
                'execution' => $execution,
                'metrics' => $this->collectMetrics($execution),
                'timestamp' => now()
            ]);

            DB::commit();
            return $result;

        } catch (ResponseException $e) {
            DB::rollBack();
            $this->handleResponseFailure($e, $responseId);
            throw new CriticalResponseException($e->getMessage(), $e);
        }
    }

    private function validateDecision(Decision $decision): void
    {
        if (!$this->validator->validateDecision($decision)) {
            throw new InvalidDecisionException('Response decision validation failed');
        }

        if ($decision->requiresEscalation()) {
            $this->emergency->escalateDecision($decision);
        }
    }

    private function executeActions(array $actions, ResponseContext $context): Execution
    {
        $execution = $this->actionController->execute($actions, $context);
        
        if (!$execution->isSuccessful()) {
            $this->emergency->handleExecutionFailure($execution);
            throw new ExecutionException('Action execution failed');
        }
        
        return $execution;
    }

    private function monitorExecution(Execution $execution): void
    {
        $monitoringResult = $this->monitor->trackExecution($execution);
        
        if ($monitoringResult->hasAnomalies()) {
            $this->emergency->handleAnomalies($monitoringResult->getAnomalies());
        }
    }
}
