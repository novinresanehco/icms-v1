<?php

namespace App\Core\Audit\Pipeline;

class AnalysisPipeline
{
    private array $stages = [];
    private LoggerInterface $logger;
    private MetricsCollector $metrics;

    public function __construct(LoggerInterface $logger, MetricsCollector $metrics)
    {
        $this->logger = $logger;
        $this->metrics = $metrics;
    }

    public function addStage(PipelineStage $stage): self
    {
        $this->stages[] = $stage;
        return $this;
    }

    public function process($input): PipelineResult
    {
        $context = new PipelineContext();
        $result = $input;
        $startTime = microtime(true);

        try {
            foreach ($this->stages as $stage) {
                $this->logger->info('Starting pipeline stage', [
                    'stage' => get_class($stage)
                ]);

                $stageStartTime = microtime(true);
                $result = $stage->process($result, $context);
                
                $stageDuration = (microtime(true) - $stageStartTime) * 1000;
                $this->metrics->timing('pipeline.stage_duration', $stageDuration, [
                    'stage' => get_class($stage)
                ]);
            }

            $totalDuration = (microtime(true) - $startTime) * 1000;
            $this->metrics->timing('pipeline.total_duration', $totalDuration);

            return new PipelineResult($result, $context);
        } catch (\Exception $e) {
            $this->logger->error('Pipeline processing failed', [
                'error' => $e->getMessage()
            ]);
            throw new PipelineException('Pipeline processing failed', 0, $e);
        }
    }
}

class PipelineContext
{
    private array $data = [];
    private array $metadata = [];

    public function set(string $key, $value): void
    {
        $this->data[$key] = $value;
    }

    public function get(string $key)
    {
        return $this->data[$key] ?? null;
    }

    public function setMetadata(string $key, $value): void
    {
        $this->metadata[$key] = $value;
    }

    public function getMetadata(string $key)
    {
        return $this->metadata[$key] ?? null;
    }

    public function toArray(): array
    {
        return [
            'data' => $this->data,
            'metadata' => $this->metadata
        ];
    }
}

abstract class PipelineStage
{
    protected LoggerInterface $logger;
    protected array $config;

    public function __construct(LoggerInterface $logger, array $config = [])
    {
        $this->logger = $logger;
        $this->config = $config;
    }

    abstract public function process($input, PipelineContext $context);

    protected function log(string $message, array $context = []): void
    {
        $this->logger->info($message, array_merge([
            'stage' => get_class($this)
        ], $context));
    }
}

class ValidationStage extends PipelineStage
{
    private ValidatorInterface $validator;

    public function process($input, PipelineContext $context)
    {
        $this->log('Starting validation');

        $result = $this->validator->validate($input);
        
        if (!$result->isValid()) {
            throw new ValidationException($result->getErrors());
        }

        $context->setMetadata('validation_passed', true);
        return $input;
    }
}

class TransformationStage extends PipelineStage
{
    private array $transformers;

    public function process($input, PipelineContext $context)
    {
        $this->log('Starting transformation');

        $result = $input;
        foreach ($this->transformers as $transformer) {
            $result = $transformer->transform($result);
        }

        $context->setMetadata('transformed', true);
        return $result;
    }
}

class EnrichmentStage extends PipelineStage
{
    private array $enrichers;

    public function process($input, PipelineContext $context)
    {
        $this->log('Starting enrichment');

        $result = $input;
        foreach ($this->enrichers as $enricher) {
            $result = $enricher->enrich($result);
        }

        $context->setMetadata('enriched', true);
        return $result;
    }
}

class AggregationStage extends PipelineStage
{
    private AggregatorInterface $aggregator;

    public function process($input, PipelineContext $context)
    {
        $this->log('Starting aggregation');

        $result = $this->aggregator->aggregate($input);
        
        $context->setMetadata('aggregated', true);
        return $result;
    }
}

class PersistenceStage extends PipelineStage
{
    private StorageInterface $storage;

    public function process($input, PipelineContext $context)
    {
        $this->log('Starting persistence');

        $id = $this->storage->store($input);
        
        $context->setMetadata('stored', true);
        $context->setMetadata('storage_id', $id);
        
        return $input;
    }
}

class PipelineResult
{
    private $data;
    private PipelineContext $context;

    public function __construct($data, PipelineContext $context)
    {
        $this->data = $data;
        $this->context = $context;
    }

    public function getData()
    {
        return $this->data;
    }

    public function getContext(): PipelineContext
    {
        return $this->context;
    }

    public function toArray(): array
    {
        return [
            'data' => $this->data,
            'context' => $this->context->toArray()
        ];
    }
}
