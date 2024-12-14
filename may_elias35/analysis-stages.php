<?php

namespace App\Core\Audit\Stages;

class PreProcessingStage extends PipelineStage
{
    private DataCleaner $cleaner;
    private DataNormalizer $normalizer;

    public function process($input, PipelineContext $context)
    {
        $this->log('Starting pre-processing');

        $cleaned = $this->cleaner->clean($input);
        $normalized = $this->normalizer->normalize($cleaned);

        $context->setMetadata('pre_processed', true);
        return $normalized;
    }
}

class AnalysisStage extends PipelineStage
{
    private AnalysisEngine $engine;
    private array $analyzers;

    public function process($input, PipelineContext $context)
    {
        $this->log('Starting analysis');

        $results = [];
        foreach ($this->analyzers as $analyzer) {
            $results[] = $analyzer->analyze($input);
        }

        $finalResult = $this->engine->combine($results);
        
        $context->setMetadata('analyzed', true);
        return $finalResult;
    }
}

class PostProcessingStage extends PipelineStage
{
    private array $processors;
    private ResultFormatter $formatter;

    public function process($input, PipelineContext $context)
    {
        $this->log('Starting post-processing');

        $result = $input;
        foreach ($this->processors as $processor) {
            $result = $processor->process($result);
        }

        $formatted = $this->formatter->format($result);
        
        $context->setMetadata('post_processed', true);
        return $formatted;
    }
}

class ReportingStage extends PipelineStage
{
    private ReportGenerator $generator;
    private NotificationManager $notifications;

    public function process($input, PipelineContext $context)
    {
        $this->log('Starting reporting');

        $report = $this->generator->generate($input);
        
        if ($this->shouldNotify($report)) {
            $this->notifications->sendReport($report);
        }

        $context->setMetadata('reported', true);
        return $report;
    }

    private function shouldNotify(Report $report): bool
    {
        return $report->hasFindings() || 
               $report->hasCriticalIssues() ||
               $this->config['always_notify'] ?? false;
    }
}
