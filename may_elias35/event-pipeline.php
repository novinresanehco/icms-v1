<?php

namespace App\Core\Audit;

class AuditEventPipeline
{
    private PipelineConfiguration $config;
    private array $processors;
    private array $validators;
    private array $enrichers;
    private MetricsCollector $metrics;
    private LoggerInterface $logger;

    public function __construct(
        PipelineConfiguration $config,
        MetricsCollector $metrics,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->processors = [];
        $this->validators = [];
        $this->enrichers = [];
        $this->metrics = $metrics;
        $this->logger = $logger;
    }

    public function process(AuditEvent $event): ProcessedEvent
    {
        $startTime = microtime(true);
        $context = new PipelineContext();

        try {
            // Initialize pipeline
            $this->initializePipeline($event, $context);

            // Validate event
            $this->validateEvent($event, $context);

            // Enrich event
            $enrichedEvent = $this->enrichEvent($event, $context);

            // Process event
            $processedEvent = $this->processEvent($enrichedEvent, $context);

            // Finalize processing
            $result = $this->finalizePipeline($processedEvent, $context);

            // Record metrics
            $this->recordMetrics($event, $context, microtime(true) - $startTime);

            return $result;

        } catch (ValidationException $e) {
            $this->handleValidationError($e, $event, $context);
            throw $e;
        } catch (\Exception $e) {
            $this->handleProcessingError($e, $event, $context);
            throw $e;
        }
    }

    public function addProcessor(EventProcessor $processor, int $priority = 0): self
    {
        $this->processors[$priority][] = $processor;
        ksort($this->processors);
        return $this;
    }

    public function addValidator(EventValidator $validator, int $priority = 0): self
    {
        $this->validators[$priority][] = $validator;
        ksort($this->validators);
        return $this;
    }

    public function addEnricher(EventEnricher $enricher, int $priority = 0): self
    {
        $this->enrichers[$priority][] = $enricher;
        ksort($this->enrichers);
        return $this;
    }

    protected function initializePipeline(AuditEvent $event, PipelineContext $context): void
    {
        $context->setStartTime(microtime(true));
        $context->setOriginalEvent($event);
        
        // Initialize pipeline state
        $context->setState([
            'stage' => PipelineStage::INITIALIZATION,
            'modifications' => [],
            'validations' => [],
            'enrichments' => []
        ]);

        $this->logger->debug('Pipeline initialized', [
            'event_id' => $event->getId(),
            'event_type' => $event->getType()
        ]);
    }

    protected function validateEvent(AuditEvent $event, PipelineContext $context): void
    {
        $context->setStage(PipelineStage::VALIDATION);
        $violations = [];

        foreach ($this->validators as $priority => $priorityValidators) {
            foreach ($priorityValidators as $validator) {
                try {
                    $result = $validator->validate($event);
                    $context->addValidation($validator->getName(), $result);

                    if (!$result->isValid()) {
                        $violations = array_merge($violations, $result->getViolations());
                    }
                } catch (\Exception $e) {
                    $this->handleValidatorError($e, $validator, $event);
                }
            }
        }

        if (!empty($violations)) {
            throw new ValidationException('Event validation failed', $violations);
        }
    }

    protected function enrichEvent(AuditEvent $event, PipelineContext $context): EnrichedEvent
    {
        $context->setStage(PipelineStage::ENRICHMENT);
        $enrichedData = [];

        foreach ($this->enrichers as $priority => $priorityEnrichers) {
            foreach ($priorityEnrichers as $enricher) {
                try {
                    $data = $enricher->enrich($event);
                    $enrichedData = array_merge($enrichedData, $data);
                    $context->addEnrichment($enricher->getName(), $data);
                } catch (\Exception $e) {
                    $this->handleEnricherError($e, $enricher, $event);
                }
            }
        }

        return new EnrichedEvent($event, $enrichedData);
    }

    protected function processEvent(EnrichedEvent $event, PipelineContext $context): ProcessedEvent
    {
        $context->setStage(PipelineStage::PROCESSING);
        $processedEvent = $event;

        foreach ($this->processors as $priority => $priorityProcessors) {
            foreach ($priorityProcessors as $processor) {
                try {
                    $processedEvent = $processor->process($processedEvent);
                    $context->addModification($processor->getName(), [
                        'processor' => get_class($processor),
                        'changes' => $this->detectChanges($event, $processedEvent)
                    ]);
                } catch (\Exception $e) {
                    $this->handleProcessorError($e, $processor, $event);
                }
            }
        }

        return new ProcessedEvent($processedEvent, $context->getModifications());
    }

    protected function finalizePipeline(ProcessedEvent $event, PipelineContext $context): ProcessedEvent
    {
        $context->setStage(PipelineStage::FINALIZATION);
        $context->setEndTime(microtime(true));

        // Add pipeline metadata
        $event->addMetadata('pipeline', [
            'duration' => $context->getDuration(),
            'stages' => $context->getStages(),
            'modifications' => $context->getModifications(),
            'validations' => $context->getValidations(),
            'enrichments' => $context->getEnrichments()
        ]);

        $this->logger->info('Pipeline completed', [
            'event_id' => $event->getId(),
            'duration' => $context->getDuration(),
            'modifications' => count($context->getModifications())
        ]);

        return $event;
    }

    protected function recordMetrics(
        AuditEvent $event,
        PipelineContext $context,
        float $duration
    ): void {
        $this->metrics->record([
            'pipeline_duration' => $duration,
            'validation_count' => count($context->getValidations()),
            'enrichment_count' => count($context->getEnrichments()),
            'modification_count' => count($context->getModifications()),
            'event_type' => $event->getType()
        ]);
    }

    protected function detectChanges(EnrichedEvent $original, ProcessedEvent $processed): array
    {
        $changes = [];
        $originalData = $original->toArray();
        $processedData = $processed->toArray();

        foreach ($processedData as $key => $value) {
            if (!isset($originalData[$key]) || $originalData[$key] !== $value) {
                $changes[$key] = [
                    'old' => $originalData[$key] ?? null,
                    'new' => $value
                ];
            }
        }

        return $changes;
    }
}
