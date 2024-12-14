<?php

namespace App\Core\Event;

class EventProcessor implements EventProcessorInterface
{
    private EventRegistry $registry;
    private MessageValidator $validator;
    private ProcessingEngine $engine;
    private EventLogger $logger;
    private EmergencyProtocol $emergency;
    private AlertSystem $alerts;

    public function __construct(
        EventRegistry $registry,
        MessageValidator $validator,
        ProcessingEngine $engine,
        EventLogger $logger,
        EmergencyProtocol $emergency,
        AlertSystem $alerts
    ) {
        $this->registry = $registry;
        $this->validator = $validator;
        $this->engine = $engine;
        $this->logger = $logger;
        $this->emergency = $emergency;
        $this->alerts = $alerts;
    }

    public function processEvent(EventContext $context): ProcessingResult
    {
        $processingId = $this->initializeProcessing($context);
        
        try {
            DB::beginTransaction();

            $event = $this->registry->getEvent($context->getEventId());
            $this->validateEvent($event);

            $processingChain = $this->buildProcessingChain($event);
            $this->validateProcessingChain($processingChain);

            $result = $this->executeProcessingChain($processingChain, $event);
            $this->verifyProcessingResult($result);

            DB::commit();
            return $result;

        } catch (ProcessingException $e) {
            DB::rollBack();
            $this->handleProcessingFailure($e, $processingId);
            throw new CriticalProcessingException($e->getMessage(), $e);
        }
    }

    private function validateEvent(Event $event): void
    {
        if (!$this->validator->validateEvent($event)) {
            $this->emergency->handleInvalidEvent($event);
            throw new InvalidEventException('Event validation failed');
        }
    }

    private function buildProcessingChain(Event $event): ProcessingChain
    {
        $chain = $this->engine->buildChain($event);
        
        if (!$chain->isValid()) {
            throw new ChainBuildException('Failed to build valid processing chain');
        }
        
        return $chain;
    }

    private function executeProcessingChain(
        ProcessingChain $chain, 
        Event $event
    ): ProcessingResult {
        $result = $this->engine->execute($chain, $event);
        
        if (!$result->isSuccessful()) {
            $this->emergency->handleFailedProcessing($result);
            throw new ChainExecutionException('Processing chain execution failed');
        }
        
        return $result;
    }

    private function handleProcessingFailure(
        ProcessingException $e,
        string $processingId
    ): void {
        $this->logger->logFailure($e, $processingId);
        
        if ($e->isCritical()) {
            $this->emergency->escalateToHighestLevel();
            $this->alerts->dispatchCriticalAlert(
                new ProcessingFailureAlert($e, $processingId)
            );
        }
    }
}
